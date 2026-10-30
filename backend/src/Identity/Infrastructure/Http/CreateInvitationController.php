<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\CreateInvitationCommand;
use App\Identity\Application\Command\CreateInvitationHandler;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\OrganizationAccessType;
use App\Shared\Application\Identifier\IdentifierGenerator;
use App\Shared\Application\Idempotency\IdempotencyResponse;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\Http\AuthenticatedActorResolver;
use App\Shared\Infrastructure\Http\HttpIdempotencyRequestFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CreateInvitationController
{
    public function __construct(
        private CreateInvitationHandler $handler,
        private IdempotentExecutor $idempotency,
        private HttpIdempotencyRequestFactory $idempotencyRequests,
        private ApiRequestInput $input,
        private IdentifierGenerator $identifiers,
        private AuthenticatedActorResolver $actors,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/invitations',
        name: 'api_v1_identity_create_invitation',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $organizationId): JsonResponse
    {
        $actorUserId = $this->actors->resolve($request);
        $payload = $this->input->object($request, [
            'event_id',
            'email',
            'target_access_type',
            'roles',
            'expires_at',
        ]);
        $this->input->uuid($organizationId, 'organizationId');
        $eventId = $payload['event_id'] ?? null;
        if (null !== $eventId) {
            if (!is_string($eventId)) {
                throw new UnprocessableEntityHttpException('event_id must be a UUID or null.');
            }
            $eventId = $this->input->uuid($eventId, 'event_id');
        }
        $email = $this->input->requiredString($payload, 'email');
        $accessTypeCode = $this->input->requiredString($payload, 'target_access_type');
        $accessType = null === $eventId
            ? OrganizationAccessType::tryFrom($accessTypeCode)
            : EventAccessType::tryFrom($accessTypeCode);
        if (null === $accessType) {
            throw new UnprocessableEntityHttpException('target_access_type is invalid for the invitation scope.');
        }
        $rolesValue = $payload['roles'] ?? [];
        if (null === $eventId && [] === $rolesValue) {
            $roles = [];
        } else {
            $roles = $this->input->roles($rolesValue);
        }
        $expiresAt = $this->input->optionalDate($payload, 'expires_at');
        if (null === $expiresAt) {
            throw new UnprocessableEntityHttpException('expires_at is required.');
        }

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $idempotencyRecordId = $this->identifiers->generate();
        $invitationId = $this->identifiers->generate();
        $deliveryId = $this->identifiers->generate();
        $auditLogId = $this->identifiers->generate();
        $result = $this->idempotency->execute($this->idempotencyRequests->create(
            $request,
            $payload,
            $idempotencyRecordId,
            $organizationId,
            $eventId,
            $actorUserId,
            $occurredAt,
        ), function () use (
            $actorUserId,
            $organizationId,
            $eventId,
            $email,
            $accessType,
            $roles,
            $invitationId,
            $deliveryId,
            $auditLogId,
            $expiresAt,
            $occurredAt,
            $idempotencyRecordId,
        ): IdempotencyResponse {
            $created = $this->handler->handle(new CreateInvitationCommand(
                $actorUserId,
                $organizationId,
                $eventId,
                $email,
                $accessType,
                $roles,
                $invitationId,
                $deliveryId,
                $auditLogId,
                $expiresAt,
                $occurredAt,
                $idempotencyRecordId,
            ));

            return new IdempotencyResponse(202, ['data' => [
                'invitation_id' => $created->invitationId,
                'delivery_id' => $created->deliveryId,
                'delivery_status' => 'queued',
                'expires_at' => $created->expiresAt->format(DATE_ATOM),
                'roles' => array_map(static fn (EventRole $role): string => $role->value, $roles),
            ]]);
        });

        $response = new JsonResponse($result->body, $result->statusCode);
        $response->headers->set('Idempotency-Replayed', $result->replayed ? 'true' : 'false');

        return $response;
    }
}
