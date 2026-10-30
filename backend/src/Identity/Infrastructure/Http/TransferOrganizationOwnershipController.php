<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\TransferOrganizationOwnershipCommand;
use App\Identity\Application\Command\TransferOrganizationOwnershipHandler;
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

final readonly class TransferOrganizationOwnershipController
{
    public function __construct(
        private TransferOrganizationOwnershipHandler $handler,
        private IdempotentExecutor $idempotency,
        private HttpIdempotencyRequestFactory $idempotencyRequests,
        private ApiRequestInput $input,
        private IdentifierGenerator $identifiers,
        private AuthenticatedActorResolver $actors,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/ownership-transfers',
        name: 'api_v1_identity_transfer_organization_ownership',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $organizationId): JsonResponse
    {
        $currentOwnerUserId = $this->actors->resolve($request);
        $this->input->uuid($organizationId, 'organizationId');
        $payload = $this->input->object($request, [
            'new_owner_user_id',
            'previous_owner_access_type',
        ]);
        $newOwnerUserId = $this->input->uuid(
            $this->input->requiredString($payload, 'new_owner_user_id'),
            'new_owner_user_id',
        );
        $previousOwnerAccessType = OrganizationAccessType::tryFrom(
            $this->input->requiredString($payload, 'previous_owner_access_type'),
        );
        if (!in_array($previousOwnerAccessType, [
            OrganizationAccessType::ADMINISTRATOR,
            OrganizationAccessType::COLLABORATOR,
        ], true)) {
            throw new UnprocessableEntityHttpException(
                'previous_owner_access_type must be administrator or collaborator.',
            );
        }

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $idempotencyRecordId = $this->identifiers->generate();
        $auditLogId = $this->identifiers->generate();
        $result = $this->idempotency->execute($this->idempotencyRequests->create(
            $request,
            $payload,
            $idempotencyRecordId,
            $organizationId,
            null,
            $currentOwnerUserId,
            $occurredAt,
        ), function () use (
            $currentOwnerUserId,
            $newOwnerUserId,
            $organizationId,
            $previousOwnerAccessType,
            $auditLogId,
            $occurredAt,
            $idempotencyRecordId,
        ): IdempotencyResponse {
            $membershipId = $this->handler->handle(new TransferOrganizationOwnershipCommand(
                $currentOwnerUserId,
                $newOwnerUserId,
                $organizationId,
                $previousOwnerAccessType,
                $auditLogId,
                $occurredAt,
                $idempotencyRecordId,
            ));

            return new IdempotencyResponse(200, ['data' => [
                'new_owner_membership_id' => $membershipId,
                'new_owner_user_id' => $newOwnerUserId,
                'previous_owner_access_type' => $previousOwnerAccessType->value,
                'status' => 'transferred',
            ]]);
        });

        $response = new JsonResponse($result->body, $result->statusCode);
        $response->headers->set('Idempotency-Replayed', $result->replayed ? 'true' : 'false');

        return $response;
    }
}
