<?php

declare(strict_types=1);

namespace App\Ticket\Infrastructure\Http;

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

final readonly class CourseAllocationController
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
        '/api/v1/organizations/{organizationId}/events/{eventId}/course-allocations',
        name: 'api_v1_course_allocations_list',
        methods: ['GET']
    )]
    public function list(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $actorUserId = $this->actors->resolve($request);

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $authDecision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::EVENT_READ,
            $eventId,
            $occurredAt
        ));

        if (!$authDecision->allowed) {
            throw new AccessDeniedHttpException('No tienes permiso para ver las asignaciones por curso.');
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, course_name, delegate_name, product_id, product_name_snapshot,
                    stand_name_snapshot, assigned_quantity, delivered_quantity, returned_quantity,
                    updated_at
             FROM course_allocations
             WHERE organization_id = ? AND event_id = ?
             ORDER BY course_name ASC',
            [$organizationId, $eventId]
        );

        $data = array_map(static function (array $r): array {
            $assigned = (int) $r['assigned_quantity'];
            $delivered = (int) $r['delivered_quantity'];
            $returned = (int) $r['returned_quantity'];
            $remaining = max(0, $assigned - $delivered - $returned);

            return [
                'id' => (string) $r['id'],
                'course' => (string) $r['course_name'],
                'delegate' => (string) $r['delegate_name'],
                'product_id' => (string) $r['product_id'],
                'product' => (string) $r['product_name_snapshot'],
                'stand' => (string) $r['stand_name_snapshot'],
                'assigned' => $assigned,
                'delivered' => $delivered,
                'returned' => $returned,
                'remaining' => $remaining,
                'updated_at' => (string) $r['updated_at'],
            ];
        }, $rows);

        return new JsonResponse(['data' => $data]);
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/course-allocations/{allocationId}/record',
        name: 'api_v1_course_allocations_record',
        methods: ['POST']
    )]
    public function record(Request $request, string $organizationId, string $eventId, string $allocationId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $this->input->uuid($allocationId, 'allocationId');
        $actorUserId = $this->actors->resolve($request);

        $payload = $this->input->object($request, ['action', 'quantity']);
        $action = $this->input->requiredString($payload, 'action');
        if (!in_array($action, ['deliver', 'return'], true)) {
            throw new UnprocessableEntityHttpException('action must be "deliver" or "return".');
        }
        $qty = $payload['quantity'] ?? null;
        if (!is_int($qty) || $qty < 1 || $qty > 500) {
            throw new UnprocessableEntityHttpException('quantity must be an integer >= 1.');
        }

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $canManageCourse = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::COURSE_MANAGE,
            $eventId,
            $occurredAt
        ))->allowed;

        $canRedeemTicket = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::TICKET_REDEEM,
            $eventId,
            $occurredAt
        ))->allowed;

        if (!$canManageCourse && !$canRedeemTicket) {
            throw new AccessDeniedHttpException('No tienes permiso para registrar entregas o devoluciones de curso.');
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
                $allocationId,
                $actorUserId,
                $action,
                $qty,
                $occurredAt,
            ): IdempotencyResponse {
                return $this->connection->transactional(function () use (
                    $idempotencyRecordId,
                    $organizationId,
                    $eventId,
                    $allocationId,
                    $actorUserId,
                    $action,
                    $qty,
                    $occurredAt,
                ): IdempotencyResponse {
                    $row = $this->connection->fetchAssociative(
                        'SELECT * FROM course_allocations
                         WHERE id = ? AND organization_id = ? AND event_id = ?
                         FOR UPDATE',
                        [$allocationId, $organizationId, $eventId]
                    );

                    if (false === $row) {
                        throw new UnprocessableEntityHttpException('La asignación de curso no existe.');
                    }

                    $assigned = (int) $row['assigned_quantity'];
                    $delivered = (int) $row['delivered_quantity'];
                    $returned = (int) $row['returned_quantity'];
                    $remaining = max(0, $assigned - $delivered - $returned);

                    if ($qty > $remaining) {
                        throw new UnprocessableEntityHttpException(sprintf(
                            'Solo quedan %d unidades pendientes para "%s".',
                            $remaining,
                            (string) $row['course_name']
                        ));
                    }

                    $nowStr = $occurredAt->format('Y-m-d H:i:s');
                    if ('deliver' === $action) {
                        $delivered += $qty;
                    } else {
                        $returned += $qty;
                    }

                    $this->connection->executeStatement(
                        'UPDATE course_allocations
                         SET delivered_quantity = ?, returned_quantity = ?, updated_at = ?
                         WHERE id = ?',
                        [$delivered, $returned, $nowStr, $allocationId]
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
                            'course_allocation.' . $action,
                            'course_allocation',
                            $allocationId,
                            $idempotencyRecordId,
                            json_encode([
                                'course' => $row['course_name'],
                                'action' => $action,
                                'quantity' => $qty,
                                'delivered' => $delivered,
                                'returned' => $returned,
                            ], JSON_THROW_ON_ERROR),
                            $nowStr,
                        ]
                    );

                    return new IdempotencyResponse(200, [
                        'data' => [
                            'id' => $allocationId,
                            'course' => (string) $row['course_name'],
                            'delegate' => (string) $row['delegate_name'],
                            'product' => (string) $row['product_name_snapshot'],
                            'stand' => (string) $row['stand_name_snapshot'],
                            'assigned' => $assigned,
                            'delivered' => $delivered,
                            'returned' => $returned,
                            'remaining' => max(0, $assigned - $delivered - $returned),
                        ],
                    ]);
                });
            });
        } catch (HttpExceptionInterface | IdempotencyConflict $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UnprocessableEntityHttpException('Error al actualizar asignación de curso: ' . $e->getMessage());
        }

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/course-allocations',
        name: 'api_v1_course_allocations_create',
        methods: ['POST']
    )]
    public function create(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $actorUserId = $this->actors->resolve($request);

        $payload = $this->input->object($request, ['course', 'delegate', 'product_id', 'assigned_quantity']);
        $course = $this->input->requiredString($payload, 'course', 120);
        $delegate = $this->input->requiredString($payload, 'delegate', 160);
        $productId = $this->input->uuid($this->input->requiredString($payload, 'product_id'), 'product_id');

        $assigned = $payload['assigned_quantity'] ?? null;
        if (!is_int($assigned) || $assigned < 1 || $assigned > 5000) {
            throw new UnprocessableEntityHttpException('assigned_quantity must be an integer >= 1.');
        }

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $canManageCourse = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::COURSE_MANAGE,
            $eventId,
            $occurredAt
        ))->allowed;

        if (!$canManageCourse) {
            throw new AccessDeniedHttpException('No tienes permiso (course.manage) para asignar cupos a cursos.');
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
        ), function () use ($idempotencyRecordId, $organizationId, $eventId, $actorUserId, $course, $delegate, $productId, $assigned, $occurredAt): IdempotencyResponse {
            return $this->connection->transactional(function () use ($idempotencyRecordId, $organizationId, $eventId, $actorUserId, $course, $delegate, $productId, $assigned, $occurredAt): IdempotencyResponse {
                $prodRow = $this->connection->fetchAssociative(
                    'SELECT p.id, p.name, s.name AS stand_name
                     FROM products p
                     JOIN stands s ON s.id = p.stand_id
                     WHERE p.id = ? AND p.event_id = ?',
                    [$productId, $eventId]
                );

                if (false === $prodRow) {
                    throw new UnprocessableEntityHttpException('El producto seleccionado no pertenece a este evento.');
                }

                $allocationId = $this->identifiers->generate();
                $nowStr = $occurredAt->format('Y-m-d H:i:s');

                $this->connection->executeStatement(
                    'INSERT INTO course_allocations (
                        id, organization_id, event_id, course_name, delegate_name,
                        product_id, product_name_snapshot, stand_name_snapshot,
                        assigned_quantity, delivered_quantity, returned_quantity,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $allocationId,
                        $organizationId,
                        $eventId,
                        $course,
                        $delegate,
                        $productId,
                        (string) $prodRow['name'],
                        (string) $prodRow['stand_name'],
                        $assigned,
                        0,
                        0,
                        $nowStr,
                        $nowStr,
                    ]
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
                        'course_allocation.created',
                        'course_allocation',
                        $allocationId,
                        $idempotencyRecordId,
                        json_encode([
                            'course' => $course,
                            'delegate' => $delegate,
                            'product' => $prodRow['name'],
                            'assigned_quantity' => $assigned,
                        ], JSON_THROW_ON_ERROR),
                        $nowStr,
                    ]
                );

                return new IdempotencyResponse(201, [
                    'data' => [
                        'id' => $allocationId,
                        'course' => $course,
                        'delegate' => $delegate,
                        'product_id' => $productId,
                        'product' => (string) $prodRow['name'],
                        'stand' => (string) $prodRow['stand_name'],
                        'assigned' => $assigned,
                        'delivered' => 0,
                        'returned' => 0,
                        'remaining' => $assigned,
                    ],
                ]);
            });
        });

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }
}
