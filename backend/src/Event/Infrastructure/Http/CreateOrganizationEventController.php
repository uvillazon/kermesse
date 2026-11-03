<?php

declare(strict_types=1);

namespace App\Event\Infrastructure\Http;

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

final readonly class CreateOrganizationEventController
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
        '/api/v1/organizations/{organizationId}/events',
        name: 'api_v1_events_create',
        methods: ['POST']
    )]
    public function __invoke(Request $request, string $organizationId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $actorUserId = $this->actors->resolve($request);

        $payload = $this->input->object($request, ['name', 'slug', 'starts_at']);
        $name = $this->input->requiredString($payload, 'name', 160);
        $slug = strtolower($this->input->requiredString($payload, 'slug', 96));
        $startsAtRaw = $this->input->optionalString($payload, 'starts_at', 40);

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $authDecision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::ORGANIZATION_MANAGE_MEMBERS,
            null,
            $occurredAt
        ));

        if (!$authDecision->allowed) {
            throw new AccessDeniedHttpException('Solo propietarios o administradores organizacionales pueden crear nuevos eventos.');
        }

        $idempotencyRecordId = $this->identifiers->generate();

        try {
            $result = $this->idempotency->execute($this->idempotencyRequests->create(
                $request,
                $payload,
                $idempotencyRecordId,
                $organizationId,
                null,
                $actorUserId,
                $occurredAt,
            ), function () use (
                $idempotencyRecordId,
                $organizationId,
                $actorUserId,
                $name,
                $slug,
                $startsAtRaw,
                $occurredAt,
            ): IdempotencyResponse {
                return $this->connection->transactional(function () use (
                    $idempotencyRecordId,
                    $organizationId,
                    $actorUserId,
                    $name,
                    $slug,
                    $startsAtRaw,
                    $occurredAt,
                ): IdempotencyResponse {
                    $existing = $this->connection->fetchOne(
                        'SELECT id FROM events WHERE organization_id = ? AND slug = ?',
                        [$organizationId, $slug]
                    );
                    if (false !== $existing) {
                        throw new UnprocessableEntityHttpException(sprintf('El slug "%s" ya está en uso en esta organización.', $slug));
                    }

                    $eventId = $this->identifiers->generate();
                    $nowStr = $occurredAt->format('Y-m-d H:i:s');
                    $startsAt = $startsAtRaw ? (new \DateTimeImmutable($startsAtRaw))->format('Y-m-d H:i:s') : $nowStr;

                    $this->connection->executeStatement(
                        'INSERT INTO events (
                            id, organization_id, slug, name, status, starts_at, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $eventId,
                            $organizationId,
                            $slug,
                            $name,
                            'draft',
                            $startsAt,
                            $nowStr,
                            $nowStr,
                        ]
                    );

                    // Asignar acceso operativo como EVENT_MANAGER al creador del evento
                    $eventMembershipId = $this->identifiers->generate();
                    $this->connection->executeStatement(
                        'INSERT INTO event_memberships (
                            id, event_id, user_id, access_type, status, valid_from, valid_until, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $eventMembershipId,
                            $eventId,
                            $actorUserId,
                            'operational',
                            'active',
                            null,
                            null,
                            $nowStr,
                            $nowStr,
                        ]
                    );

                    $managerRoleId = $this->connection->fetchOne(
                        'SELECT id FROM roles WHERE code = ? LIMIT 1',
                        ['EVENT_MANAGER']
                    );
                    if (false !== $managerRoleId) {
                        $this->connection->executeStatement(
                            'INSERT INTO event_membership_roles (event_membership_id, role_id, granted_by, granted_at) VALUES (?, ?, ?, ?)',
                            [$eventMembershipId, $managerRoleId, $actorUserId, $nowStr]
                        );
                    }

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
                            'event.created',
                            'event',
                            $eventId,
                            $idempotencyRecordId,
                            json_encode([
                                'name' => $name,
                                'slug' => $slug,
                                'status' => 'draft',
                            ], JSON_THROW_ON_ERROR),
                            $nowStr,
                        ]
                    );

                    return new IdempotencyResponse(201, [
                        'data' => [
                            'id' => $eventId,
                            'slug' => $slug,
                            'name' => $name,
                            'status' => 'draft',
                            'date' => (new \DateTimeImmutable($startsAt))->format('d M Y'),
                            'stands' => 0,
                        ],
                    ]);
                });
            });
        } catch (HttpExceptionInterface | IdempotencyConflict $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UnprocessableEntityHttpException('Error al crear el evento: ' . $e->getMessage());
        }

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }
}
