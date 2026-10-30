<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\AssignEventAccessCommand;
use App\Identity\Application\Command\AssignEventAccessHandler;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventRole;
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

final readonly class AssignEventAccessController
{
    public function __construct(
        private AssignEventAccessHandler $handler,
        private IdempotentExecutor $idempotency,
        private HttpIdempotencyRequestFactory $idempotencyRequests,
        private ApiRequestInput $input,
        private IdentifierGenerator $identifiers,
        private AuthenticatedActorResolver $actors,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/access-assignments',
        name: 'api_v1_identity_assign_event_access',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $actorUserId = $this->actors->resolve($request);
        $payload = $this->input->object($request, [
            'target_user_id',
            'access_type',
            'roles',
            'valid_from',
            'valid_until',
        ]);

        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $targetUserId = $this->input->uuid($this->input->requiredString($payload, 'target_user_id'), 'target_user_id');
        $accessType = EventAccessType::tryFrom($this->input->requiredString($payload, 'access_type'));
        if (null === $accessType) {
            throw new UnprocessableEntityHttpException('access_type is invalid.');
        }
        $roles = $this->input->roles($payload['roles'] ?? null);
        $validFrom = $this->input->optionalDate($payload, 'valid_from');
        $validUntil = $this->input->optionalDate($payload, 'valid_until');
        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $idempotencyRecordId = $this->identifiers->generate();
        $eventMembershipId = $this->identifiers->generate();
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
            $targetUserId,
            $organizationId,
            $eventId,
            $eventMembershipId,
            $auditLogId,
            $accessType,
            $roles,
            $occurredAt,
            $validFrom,
            $validUntil,
            $idempotencyRecordId,
        ): IdempotencyResponse {
            $membershipId = $this->handler->handle(new AssignEventAccessCommand(
                $actorUserId,
                $targetUserId,
                $organizationId,
                $eventId,
                $eventMembershipId,
                $auditLogId,
                $accessType,
                $roles,
                $occurredAt,
                $validFrom,
                $validUntil,
                $idempotencyRecordId,
            ));

            return new IdempotencyResponse(201, [
                'data' => [
                    'event_membership_id' => $membershipId,
                    'target_user_id' => $targetUserId,
                    'organization_id' => $organizationId,
                    'event_id' => $eventId,
                    'access_type' => $accessType->value,
                    'roles' => array_map(static fn (EventRole $role): string => $role->value, $roles),
                ],
            ]);
        });

        $response = new JsonResponse($result->body, $result->statusCode);
        $response->headers->set('Idempotency-Replayed', $result->replayed ? 'true' : 'false');

        return $response;
    }

}
