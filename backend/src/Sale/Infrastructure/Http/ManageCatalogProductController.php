<?php

declare(strict_types=1);

namespace App\Sale\Infrastructure\Http;

use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Domain\Permission;
use App\Shared\Application\Idempotency\IdempotencyConflict;
use App\Shared\Application\Idempotency\IdempotencyResponse;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifier\IdentifierGenerator;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\Http\AuthenticatedActorResolver;
use App\Shared\Infrastructure\Http\HttpIdempotencyRequestFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ManageCatalogProductController
{
    public function __construct(
        private Connection $connection,
        private AuthorizationPolicy $policy,
        private IdempotentExecutor $idempotency,
        private HttpIdempotencyRequestFactory $idempotencyRequests,
        private ApiRequestInput $input,
        private IdentifierGenerator $identifiers,
        private AuthenticatedActorResolver $actors,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/catalog/products',
        name: 'api_v1_catalog_products_create',
        methods: ['POST']
    )]
    public function create(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $actorUserId = $this->actors->resolve($request);

        $payload = $this->input->object($request, ['stand_id', 'code', 'name', 'description', 'unit_price_minor', 'stock']);
        $standId = $this->input->requiredString($payload, 'stand_id', 36);
        $code = strtoupper($this->input->requiredString($payload, 'code', 64));
        $name = $this->input->requiredString($payload, 'name', 160);
        $description = $this->input->optionalString($payload, 'description', 500);

        $unitPriceMinor = $payload['unit_price_minor'] ?? null;
        if (!is_int($unitPriceMinor) || $unitPriceMinor < 50 || $unitPriceMinor > 10000000) {
            throw new UnprocessableEntityHttpException('unit_price_minor must be an integer >= 50 (in cents).');
        }

        $stock = $payload['stock'] ?? null;
        if (!is_int($stock) || $stock < 1 || $stock > 100000) {
            throw new UnprocessableEntityHttpException('stock must be an integer >= 1.');
        }

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $authDecision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::CATALOG_MANAGE,
            $eventId,
            $occurredAt
        ));

        if (!$authDecision->allowed) {
            throw new AccessDeniedHttpException('No tienes permiso (catalog.manage) para gestionar el catálogo.');
        }

        $idempotencyRecordId = $this->identifiers->generate();

        try {
            $result = $this->idempotency->execute($this->idempotencyRequests->create(
                $request,
                $payload,
                $idempotencyRecordId,
                $organizationId,
                $eventId,
                $actorUserId,
                $occurredAt,
            ), function () use (
                $idempotencyRecordId,
                $organizationId,
                $eventId,
                $actorUserId,
                $standId,
                $code,
                $name,
                $description,
                $unitPriceMinor,
                $stock,
                $occurredAt,
            ): IdempotencyResponse {
                return $this->connection->transactional(function () use (
                    $idempotencyRecordId,
                    $organizationId,
                    $eventId,
                    $actorUserId,
                    $standId,
                    $code,
                    $name,
                    $description,
                    $unitPriceMinor,
                    $stock,
                    $occurredAt,
                ): IdempotencyResponse {
                    $standRow = $this->connection->fetchAssociative(
                        'SELECT id, name FROM stands WHERE (id = ? OR name = ?) AND event_id = ? LIMIT 1',
                        [$standId, $standId, $eventId]
                    );

                    if (false === $standRow) {
                        throw new UnprocessableEntityHttpException('El puesto seleccionado no existe en este evento.');
                    }

                    $resolvedStandId = (string) $standRow['id'];
                    $existingCode = $this->connection->fetchOne(
                        'SELECT id FROM products WHERE event_id = ? AND code = ?',
                        [$eventId, $code]
                    );

                    if (false !== $existingCode) {
                        throw new UnprocessableEntityHttpException(sprintf('El código "%s" ya está registrado en el catálogo de este evento.', $code));
                    }

                    $productId = $this->identifiers->generate();
                    $nowStr = $occurredAt->format('Y-m-d H:i:s');

                    $this->connection->executeStatement(
                        'INSERT INTO products (
                            id, event_id, stand_id, code, name, description, unit_price_minor, currency,
                            stock, sold, active, version, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $productId,
                            $eventId,
                            $resolvedStandId,
                            $code,
                            $name,
                            $description,
                            $unitPriceMinor,
                            'BOB',
                            $stock,
                            0,
                            1,
                            1,
                            $nowStr,
                            $nowStr,
                        ]
                    );

                    $auditId = $this->identifiers->generate();
                    $this->connection->executeStatement(
                        'INSERT INTO audit_logs (
                            id, organization_id, event_id, actor_user_id, action, aggregate_type, aggregate_id,
                            request_id, metadata_json, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $auditId,
                            $organizationId,
                            $eventId,
                            $actorUserId,
                            'catalog.product_created',
                            'product',
                            $productId,
                            $idempotencyRecordId,
                            json_encode([
                                'code' => $code,
                                'name' => $name,
                                'stand_id' => $resolvedStandId,
                                'unit_price_minor' => $unitPriceMinor,
                                'stock' => $stock,
                            ], JSON_THROW_ON_ERROR),
                            $nowStr,
                        ]
                    );

                    return new IdempotencyResponse(201, [
                        'data' => [
                            'id' => $productId,
                            'stand_id' => $resolvedStandId,
                            'stand_name' => (string) $standRow['name'],
                            'code' => $code,
                            'name' => $name,
                            'description' => $description,
                            'unit_price' => round($unitPriceMinor / 100, 2),
                            'unit_price_minor' => $unitPriceMinor,
                            'currency' => 'BOB',
                            'stock' => $stock,
                            'sold' => 0,
                            'available_quantity' => $stock,
                            'status' => 'active',
                            'active' => true,
                            'version' => 1,
                        ],
                    ]);
                });
            });
        } catch (HttpExceptionInterface | IdempotencyConflict $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UnprocessableEntityHttpException('Error al crear el producto: ' . $e->getMessage());
        }

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/catalog/products/{productId}',
        name: 'api_v1_catalog_products_update',
        methods: ['PATCH']
    )]
    public function update(Request $request, string $organizationId, string $eventId, string $productId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $this->input->uuid($productId, 'productId');
        $actorUserId = $this->actors->resolve($request);

        $payload = $this->input->object($request, ['name', 'unit_price_minor', 'stock', 'active']);

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $authDecision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::CATALOG_MANAGE,
            $eventId,
            $occurredAt
        ));

        if (!$authDecision->allowed) {
            throw new AccessDeniedHttpException('No tienes permiso (catalog.manage) para modificar el catálogo.');
        }

        $idempotencyRecordId = $this->identifiers->generate();

        try {
            $result = $this->idempotency->execute($this->idempotencyRequests->create(
                $request,
                $payload,
                $idempotencyRecordId,
                $organizationId,
                $eventId,
                $actorUserId,
                $occurredAt,
            ), function () use (
                $idempotencyRecordId,
                $organizationId,
                $eventId,
                $productId,
                $actorUserId,
                $payload,
                $occurredAt,
            ): IdempotencyResponse {
                return $this->connection->transactional(function () use (
                    $idempotencyRecordId,
                    $organizationId,
                    $eventId,
                    $productId,
                    $actorUserId,
                    $payload,
                    $occurredAt,
                ): IdempotencyResponse {
                    $productRow = $this->connection->fetchAssociative(
                        'SELECT p.*, s.name as stand_name
                         FROM products p
                         JOIN stands s ON s.id = p.stand_id
                         WHERE p.id = ? AND p.event_id = ?
                         FOR UPDATE',
                        [$productId, $eventId]
                    );

                    if (false === $productRow) {
                        throw new UnprocessableEntityHttpException('El producto no existe en este evento.');
                    }

                    $newName = isset($payload['name'])
                        ? $this->input->requiredString($payload, 'name', 160)
                        : (string) $productRow['name'];

                    $newPriceMinor = isset($payload['unit_price_minor']) && is_int($payload['unit_price_minor'])
                        ? $payload['unit_price_minor']
                        : (int) $productRow['unit_price_minor'];

                    $newStock = isset($payload['stock']) && is_int($payload['stock'])
                        ? $payload['stock']
                        : (int) $productRow['stock'];

                    $sold = (int) $productRow['sold'];
                    if ($newStock < $sold) {
                        throw new UnprocessableEntityHttpException(sprintf('El stock (%d) no puede ser menor a las unidades ya vendidas (%d).', $newStock, $sold));
                    }

                    $newActive = array_key_exists('active', $payload) && is_bool($payload['active'])
                        ? $payload['active']
                        : (bool) $productRow['active'];

                    $newVersion = ((int) $productRow['version']) + 1;
                    $nowStr = $occurredAt->format('Y-m-d H:i:s');

                    $this->connection->executeStatement(
                        'UPDATE products
                         SET name = ?, unit_price_minor = ?, stock = ?, active = ?, version = ?, updated_at = ?
                         WHERE id = ?',
                        [$newName, $newPriceMinor, $newStock, $newActive ? 1 : 0, $newVersion, $nowStr, $productId]
                    );

                    $auditId = $this->identifiers->generate();
                    $this->connection->executeStatement(
                        'INSERT INTO audit_logs (
                            id, organization_id, event_id, actor_user_id, action, aggregate_type, aggregate_id,
                            request_id, metadata_json, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $auditId,
                            $organizationId,
                            $eventId,
                            $actorUserId,
                            'catalog.product_updated',
                            'product',
                            $productId,
                            $idempotencyRecordId,
                            json_encode([
                                'name' => $newName,
                                'unit_price_minor' => $newPriceMinor,
                                'stock' => $newStock,
                                'active' => $newActive,
                                'version' => $newVersion,
                            ], JSON_THROW_ON_ERROR),
                            $nowStr,
                        ]
                    );

                    return new IdempotencyResponse(200, [
                        'data' => [
                            'id' => $productId,
                            'stand_id' => (string) $productRow['stand_id'],
                            'stand_name' => (string) $productRow['stand_name'],
                            'code' => (string) $productRow['code'],
                            'name' => $newName,
                            'description' => $productRow['description'],
                            'unit_price' => round($newPriceMinor / 100, 2),
                            'unit_price_minor' => $newPriceMinor,
                            'currency' => (string) $productRow['currency'],
                            'stock' => $newStock,
                            'sold' => $sold,
                            'available_quantity' => max(0, $newStock - $sold),
                            'status' => $newActive ? 'active' : 'paused',
                            'active' => $newActive,
                            'version' => $newVersion,
                        ],
                    ]);
                });
            });
        } catch (HttpExceptionInterface | IdempotencyConflict $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UnprocessableEntityHttpException('Error al actualizar el producto: ' . $e->getMessage());
        }

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/catalog/stands',
        name: 'api_v1_catalog_stands_list',
        methods: ['GET']
    )]
    public function listStands(string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.name, s.manager_name,
                    (SELECT COUNT(*) FROM products p WHERE p.stand_id = s.id) AS products_count
             FROM stands s
             WHERE s.event_id = ?
             ORDER BY s.name ASC',
            [$eventId]
        );

        $data = array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'name' => (string) $r['name'],
            'manager' => (string) $r['manager_name'],
            'products_count' => (int) $r['products_count'],
        ], $rows);

        return new JsonResponse(['data' => $data]);
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/catalog/stands',
        name: 'api_v1_catalog_stands_create',
        methods: ['POST']
    )]
    public function createStand(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $actorUserId = $this->actors->resolve($request);

        $payload = $this->input->object($request, ['name', 'manager']);
        $name = $this->input->requiredString($payload, 'name', 160);
        $manager = $this->input->requiredString($payload, 'manager', 160);

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $authDecision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::CATALOG_MANAGE,
            $eventId,
            $occurredAt
        ));

        if (!$authDecision->allowed) {
            throw new AccessDeniedHttpException('No tienes permiso (catalog.manage) para crear puestos.');
        }

        $idempotencyRecordId = $this->identifiers->generate();

        $result = $this->idempotency->execute($this->idempotencyRequests->create(
            $request,
            $payload,
            $idempotencyRecordId,
            $organizationId,
            $eventId,
            $actorUserId,
            $occurredAt,
        ), function () use ($idempotencyRecordId, $organizationId, $eventId, $actorUserId, $name, $manager, $occurredAt): IdempotencyResponse {
            return $this->connection->transactional(function () use ($idempotencyRecordId, $organizationId, $eventId, $actorUserId, $name, $manager, $occurredAt): IdempotencyResponse {
                $standId = $this->identifiers->generate();
                $nowStr = $occurredAt->format('Y-m-d H:i:s');

                $this->connection->executeStatement(
                    'INSERT INTO stands (id, event_id, name, manager_name, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$standId, $eventId, $name, $manager, $nowStr]
                );

                $this->connection->executeStatement(
                    'INSERT INTO audit_logs (
                        id, organization_id, event_id, actor_user_id, action, aggregate_type, aggregate_id,
                        request_id, metadata_json, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $this->identifiers->generate(),
                        $organizationId,
                        $eventId,
                        $actorUserId,
                        'catalog.stand_created',
                        'stand',
                        $standId,
                        $idempotencyRecordId,
                        json_encode(['name' => $name, 'manager' => $manager], JSON_THROW_ON_ERROR),
                        $nowStr,
                    ]
                );

                return new IdempotencyResponse(201, [
                    'data' => [
                        'id' => $standId,
                        'name' => $name,
                        'manager' => $manager,
                    ],
                ]);
            });
        });

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }
}
