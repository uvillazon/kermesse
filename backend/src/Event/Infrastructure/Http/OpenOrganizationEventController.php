<?php

declare(strict_types=1);

namespace App\Event\Infrastructure\Http;

use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Domain\EventStatus;
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
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Abre un evento en borrador para que pueda vender y canjear.
 *
 * Solo pasa de `draft` a `open`: un evento cerrado no se reabre (queda en modo consulta).
 * Lo autoriza el mismo permiso que crear eventos (propietario o administrador de la
 * organización) mientras no se decida quién gestiona la habilitación comercial
 * (RN-COM-001), que todavía no está implementada.
 */
final readonly class OpenOrganizationEventController
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
        '/api/v1/organizations/{organizationId}/events/{eventId}/opening',
        name: 'api_v1_events_open',
        methods: ['POST']
    )]
    public function __invoke(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $actorUserId = $this->actors->resolve($request);
        // La acción no lleva datos: el cuerpo se ignora y la idempotencia usa una carga vacía.
        $payload = [];
        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $decision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::ORGANIZATION_MANAGE_MEMBERS,
            null,
            $occurredAt,
        ));
        if (!$decision->allowed) {
            throw new AccessDeniedHttpException('Solo propietarios o administradores de la organización pueden abrir eventos.');
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
            ), fn (): IdempotencyResponse => $this->connection->transactional(
                fn (): IdempotencyResponse => $this->open($organizationId, $eventId, $actorUserId, $idempotencyRecordId, $occurredAt),
            ));
        } catch (IdempotencyConflict $e) {
            throw $e;
        }

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }

    private function open(
        string $organizationId,
        string $eventId,
        string $actorUserId,
        string $requestId,
        \DateTimeImmutable $occurredAt,
    ): IdempotencyResponse {
        $event = $this->connection->fetchAssociative(
            'SELECT id, slug, name, status, starts_at FROM events WHERE id = ? AND organization_id = ? FOR UPDATE',
            [$eventId, $organizationId],
        );
        if (false === $event) {
            throw new NotFoundHttpException('El evento no existe en esta organización.');
        }

        $status = EventStatus::tryFrom((string) $event['status']);
        if (EventStatus::OPEN === $status) {
            throw new ConflictHttpException('El evento ya está abierto.');
        }
        if (EventStatus::DRAFT !== $status) {
            throw new ConflictHttpException('Un evento cerrado no se puede volver a abrir.');
        }

        $now = $occurredAt->format('Y-m-d H:i:s');
        $this->connection->update('events', ['status' => EventStatus::OPEN->value, 'updated_at' => $now], ['id' => $eventId]);

        $this->connection->insert('audit_logs', [
            'id' => $this->identifiers->generate(),
            'organization_id' => $organizationId,
            'event_id' => $eventId,
            'actor_user_id' => $actorUserId,
            'action' => 'event.opened',
            'aggregate_type' => 'event',
            'aggregate_id' => $eventId,
            'request_id' => $requestId,
            'metadata_json' => json_encode([
                'name' => (string) $event['name'],
                'previous_status' => EventStatus::DRAFT->value,
                'status' => EventStatus::OPEN->value,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        $startsAt = null === $event['starts_at']
            ? null
            : new \DateTimeImmutable((string) $event['starts_at'], new \DateTimeZone('UTC'));

        return new IdempotencyResponse(200, [
            'data' => [
                'id' => $eventId,
                'slug' => (string) $event['slug'],
                'name' => (string) $event['name'],
                'status' => EventStatus::OPEN->value,
                'starts_at' => $startsAt?->format(\DATE_ATOM),
                'date' => $startsAt?->format('d M Y'),
            ],
        ]);
    }
}
