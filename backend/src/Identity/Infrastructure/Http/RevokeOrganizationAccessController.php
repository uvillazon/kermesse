<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\RevokeOrganizationAccessCommand;
use App\Identity\Application\Command\RevokeOrganizationAccessHandler;
use App\Shared\Application\Identifier\IdentifierGenerator;
use App\Shared\Application\Idempotency\IdempotencyResponse;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\Http\AuthenticatedActorResolver;
use App\Shared\Infrastructure\Http\HttpIdempotencyRequestFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RevokeOrganizationAccessController
{
    public function __construct(
        private RevokeOrganizationAccessHandler $handler,
        private IdempotentExecutor $idempotency,
        private HttpIdempotencyRequestFactory $idempotencyRequests,
        private ApiRequestInput $input,
        private IdentifierGenerator $identifiers,
        private AuthenticatedActorResolver $actors,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/access-assignments/{targetUserId}',
        name: 'api_v1_identity_revoke_organization_access',
        methods: ['DELETE'],
    )]
    public function __invoke(Request $request, string $organizationId, string $targetUserId): JsonResponse
    {
        $actorUserId = $this->actors->resolve($request);
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($targetUserId, 'targetUserId');
        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $idempotencyRecordId = $this->identifiers->generate();
        $auditLogId = $this->identifiers->generate();

        $result = $this->idempotency->execute($this->idempotencyRequests->create(
            $request,
            [],
            $idempotencyRecordId,
            $organizationId,
            null,
            $actorUserId,
            $occurredAt,
        ), function () use ($actorUserId, $targetUserId, $organizationId, $auditLogId, $occurredAt, $idempotencyRecordId): IdempotencyResponse {
            $membershipId = $this->handler->handle(new RevokeOrganizationAccessCommand(
                $actorUserId,
                $targetUserId,
                $organizationId,
                $auditLogId,
                $occurredAt,
                $idempotencyRecordId,
            ));

            return new IdempotencyResponse(200, ['data' => [
                'organization_membership_id' => $membershipId,
                'target_user_id' => $targetUserId,
                'status' => 'revoked',
            ]]);
        });

        $response = new JsonResponse($result->body, $result->statusCode);
        $response->headers->set('Idempotency-Replayed', $result->replayed ? 'true' : 'false');

        return $response;
    }
}
