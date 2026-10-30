<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditLogWriter;
use App\Identity\Application\Access\AccessDirectory;
use App\Identity\Application\Access\InvitationWriter;
use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Application\Invitation\CreatedInvitation;
use App\Identity\Application\Invitation\InvitationDeliveryOutbox;
use App\Identity\Application\Invitation\InvitationTokenGenerator;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\EventStatus;
use App\Identity\Domain\OrganizationAccessType;
use App\Identity\Domain\Permission;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;

final readonly class CreateInvitationHandler
{
    public function __construct(
        private TransactionalExecutor $transactions,
        private InvitationWriter $invitations,
        private InvitationTokenGenerator $tokens,
        private InvitationDeliveryOutbox $deliveries,
        private AccessDirectory $directory,
        private AuthorizationPolicy $authorization,
        private AuditLogWriter $auditLog,
    ) {
    }

    public function handle(CreateInvitationCommand $command): CreatedInvitation
    {
        return $this->transactions->execute(function () use ($command): CreatedInvitation {
            $email = strtolower(trim($command->email));
            if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new AccessMutationFailed('Invitation email is invalid.');
            }
            if ($command->expiresAt <= $command->occurredAt) {
                throw new AccessMutationFailed('Invitation expiry must be in the future.');
            }

            $this->invitations->lockContext(
                $command->organizationId,
                $command->eventId,
                $command->actorUserId,
            );
            $decision = $this->authorization->decide(new AuthorizationRequest(
                $command->actorUserId,
                $command->organizationId,
                Permission::ORGANIZATION_MANAGE_MEMBERS,
                at: $command->occurredAt,
            ));
            if (!$decision->allowed) {
                throw new AccessAuthorizationDenied('Actor not authorized: '.$decision->reason?->value);
            }

            $this->assertScope($command);
            $token = $this->tokens->generate($command->invitationId);
            if ('' === $token) {
                throw new AccessMutationFailed('Invitation token generation failed.');
            }
            $this->invitations->create(
                $command->invitationId,
                $command->organizationId,
                $command->eventId,
                $email,
                $command->targetAccessType->value,
                $command->roles,
                hash('sha256', $token),
                $command->expiresAt,
                $command->actorUserId,
                $command->occurredAt,
            );
            $this->deliveries->enqueue(
                $command->deliveryId,
                $command->invitationId,
                $command->organizationId,
                $command->eventId,
                $email,
                $this->tokens->keyVersion(),
                $command->occurredAt,
            );
            $this->auditLog->append(new AuditEntry(
                $command->auditLogId,
                $command->organizationId,
                $command->eventId,
                $command->actorUserId,
                'identity.invitation_created',
                'access_invitation',
                $command->invitationId,
                $command->requestId,
                [
                    'target_access_type' => $command->targetAccessType->value,
                    'roles' => array_map(static fn (EventRole $role): string => $role->value, $command->roles),
                    'expires_at' => $command->expiresAt->format(DATE_ATOM),
                ],
                $command->occurredAt,
            ));

            return new CreatedInvitation($command->invitationId, $command->deliveryId, $command->expiresAt);
        });
    }

    private function assertScope(CreateInvitationCommand $command): void
    {
        $roleCodes = array_map(static fn (EventRole $role): string => $role->value, $command->roles);
        if (count($roleCodes) !== count(array_unique($roleCodes))) {
            throw new AccessMutationFailed('Invitation roles must be unique.');
        }

        if (null === $command->eventId) {
            if (!$command->targetAccessType instanceof OrganizationAccessType || [] !== $roleCodes) {
                throw new AccessMutationFailed('Organization invitation requires an organization access type without event roles.');
            }
            if (OrganizationAccessType::OWNER === $command->targetAccessType) {
                throw new AccessMutationFailed('Owner access requires the ownership transfer flow.');
            }

            return;
        }

        if (!$command->targetAccessType instanceof EventAccessType || [] === $roleCodes) {
            throw new AccessMutationFailed('Event invitation requires an event access type and explicit roles.');
        }
        $event = $this->directory->findEvent($command->eventId);
        if (null === $event || $event->organizationId !== $command->organizationId) {
            throw new AccessMutationFailed('Invitation event does not belong to the organization.');
        }
        if (EventStatus::CLOSED === $event->status && EventAccessType::HISTORICAL !== $command->targetAccessType) {
            throw new AccessMutationFailed('Closed events only accept historical invitations.');
        }
        if (EventStatus::CLOSED !== $event->status && EventAccessType::HISTORICAL === $command->targetAccessType) {
            throw new AccessMutationFailed('Historical invitations require a closed event.');
        }
        if (EventAccessType::HISTORICAL === $command->targetAccessType && [EventRole::HISTORICAL_VIEWER->value] !== $roleCodes) {
            throw new AccessMutationFailed('Historical invitations only accept the historical viewer role.');
        }
        if (EventAccessType::OPERATIONAL === $command->targetAccessType && in_array(EventRole::HISTORICAL_VIEWER->value, $roleCodes, true)) {
            throw new AccessMutationFailed('Operational invitations cannot use the historical viewer role.');
        }
    }
}
