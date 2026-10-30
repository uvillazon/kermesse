<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\ChangeEventRolesCommand;
use App\Identity\Application\Command\ChangeEventRolesHandler;
use App\Identity\Domain\EventRole;
use App\Shared\Application\Identifier\IdentifierGenerator;
use App\Shared\Application\Idempotency\IdempotencyResponse;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\Http\AuthenticatedActorResolver;
use App\Shared\Infrastructure\Http\HttpIdempotencyRequestFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ChangeEventRolesController
{
    public function __construct(
        private ChangeEventRolesHandler $handler,
        private IdempotentExecutor $idempotency,
        private HttpIdempotencyRequestFactory $idempotencyRequests,
        private ApiRequestInput $input,
        private IdentifierGenerator $identifiers,
        private AuthenticatedActorResolver $actors,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/access-assignments/{targetUserId}/roles',
        name: 'api_v1_identity_change_event_roles',
        methods: ['PATCH'],
    )]
    public function __invoke(Request $request, string $organizationId, string $eventId, string $targetUserId): JsonResponse
    {
        $actorUserId = $this->actors->resolve($request);
        $payload = $this->input->object($request, ['roles']);
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $this->input->uuid($targetUserId, 'targetUserId');
        $roles = $this->input->roles($payload['roles'] ?? null);
        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $idempotencyRecordId = $this->identifiers->generate();
        $auditLogId = $this->identifiers->generate();

        $result = $this->idempotency->execute($this->idempotencyRequests->create(
            $request,
            $payload,
            $idempotencyRecordId,
            $organizationId,
            $eventId,
            $actorUserId,
            $occurredAt,
        ), function () use ($actorUserId, $targetUserId, $organizationId, $eventId, $auditLogId, $roles, $occurredAt, $idempotencyRecordId): IdempotencyResponse {
            $membershipId = $this->handler->handle(new ChangeEventRolesCommand(
                $actorUserId,
                $targetUserId,
                $organizationId,
                $eventId,
                $auditLogId,
                $roles,
                $occurredAt,
                $idempotencyRecordId,
            ));

            return new IdempotencyResponse(200, ['data' => [
                'event_membership_id' => $membershipId,
                'target_user_id' => $targetUserId,
                'roles' => array_map(static fn (EventRole $role): string => $role->value, $roles),
            ]]);
        });

        $response = new JsonResponse($result->body, $result->statusCode);
        $response->headers->set('Idempotency-Replayed', $result->replayed ? 'true' : 'false');

        return $response;
    }
}
