<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditLogWriter;
use App\Identity\Application\Access\AccessDirectory;
use App\Identity\Application\Access\EventAssignmentWriter;
use App\Identity\Application\Access\InvitationAcceptanceRepository;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\EventStatus;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;

final readonly class AcceptInvitationHandler
{
    public function __construct(
        private TransactionalExecutor $transactions,
        private InvitationAcceptanceRepository $invitations,
        private EventAssignmentWriter $assignments,
        private AccessDirectory $directory,
        private AuditLogWriter $auditLog,
    ) {
    }

    public function handle(AcceptInvitationCommand $command): string
    {
        return $this->transactions->execute(function () use ($command): string {
            $email = strtolower(trim($command->email));
            if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new AccessMutationFailed('Invitation email is invalid.');
            }

            $invitation = $this->invitations->lockByTokenHash(hash('sha256', $command->token));
            if (null === $invitation || !$invitation->isPendingAt($command->occurredAt)) {
                throw new AccessMutationFailed('Invitation is invalid, expired or already consumed.');
            }
            if ($invitation->emailNormalized !== $email) {
                throw new AccessMutationFailed('Invitation email does not match the accepting identity.');
            }

            $this->assertInvitationScope($invitation->eventId, $invitation->eventAccessType(), $invitation->roles);
            $userId = $this->invitations->resolveIdentity(
                $command->newUserId,
                $email,
                trim($command->displayName),
                $command->occurredAt,
            );
            $organizationMembershipId = $this->invitations->activateOrganizationMembership(
                $command->newOrganizationMembershipId,
                $userId,
                $invitation->organizationId,
                $invitation->organizationAccessType(),
                null === $invitation->eventId,
                $command->occurredAt,
            );

            $eventMembershipId = null;
            if (null !== $invitation->eventId) {
                if (null === $command->newEventMembershipId) {
                    throw new AccessMutationFailed('Event membership identifier is required.');
                }
                $this->assignments->lockContext($invitation->organizationId, $invitation->eventId, [$userId]);
                $event = $this->directory->findEvent($invitation->eventId);
                $eventAccessType = $invitation->eventAccessType();
                if (null === $event || null === $eventAccessType) {
                    throw new AccessMutationFailed('Invitation event does not exist.');
                }
                if (EventStatus::CLOSED === $event->status && EventAccessType::HISTORICAL !== $eventAccessType) {
                    throw new AccessMutationFailed('Closed events only accept historical invitations.');
                }
                if (EventStatus::CLOSED !== $event->status && EventAccessType::HISTORICAL === $eventAccessType) {
                    throw new AccessMutationFailed('Historical invitations require a closed event.');
                }
                $eventMembershipId = $this->assignments->save(
                    $command->newEventMembershipId,
                    $userId,
                    $invitation->organizationId,
                    $invitation->eventId,
                    $eventAccessType,
                    $invitation->roles,
                    $invitation->invitedBy,
                    $command->occurredAt,
                );
            }

            $this->invitations->markAccepted($invitation->id, $userId, $command->occurredAt);
            $this->auditLog->append(new AuditEntry(
                $command->auditLogId,
                $invitation->organizationId,
                $invitation->eventId,
                $userId,
                'identity.invitation_accepted',
                'access_invitation',
                $invitation->id,
                $command->requestId,
                [
                    'organization_membership_id' => $organizationMembershipId,
                    'event_membership_id' => $eventMembershipId,
                    'access_type' => $invitation->targetAccessType,
                    'roles' => array_map(static fn (EventRole $role): string => $role->value, $invitation->roles),
                ],
                $command->occurredAt,
            ));

            return $userId;
        });
    }

    /** @param list<EventRole> $roles */
    private function assertInvitationScope(?string $eventId, ?EventAccessType $accessType, array $roles): void
    {
        if (null === $eventId) {
            if ([] !== $roles) {
                throw new AccessMutationFailed('Organization invitations cannot include event roles.');
            }

            return;
        }

        $roleCodes = array_map(static fn (EventRole $role): string => $role->value, $roles);
        if (null === $accessType || [] === $roleCodes || count($roleCodes) !== count(array_unique($roleCodes))) {
            throw new AccessMutationFailed('Event invitation requires unique explicit roles.');
        }
        if (EventAccessType::HISTORICAL === $accessType && [EventRole::HISTORICAL_VIEWER->value] !== $roleCodes) {
            throw new AccessMutationFailed('Historical invitations only accept the historical viewer role.');
        }
        if (EventAccessType::OPERATIONAL === $accessType && in_array(EventRole::HISTORICAL_VIEWER->value, $roleCodes, true)) {
            throw new AccessMutationFailed('Operational invitations cannot use the historical viewer role.');
        }
    }
}
