<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditLogWriter;
use App\Identity\Application\Access\AccessDirectory;
use App\Identity\Application\Access\EventAssignmentWriter;
use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventStatus;
use App\Identity\Domain\Permission;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;

final readonly class AssignEventAccessHandler
{
    public function __construct(
        private TransactionalExecutor $transactions,
        private EventAssignmentWriter $assignments,
        private AccessDirectory $directory,
        private AuthorizationPolicy $authorization,
        private AuditLogWriter $auditLog,
    ) {
    }

    public function handle(AssignEventAccessCommand $command): string
    {
        return $this->transactions->execute(function () use ($command): string {
            $this->assignments->lockContext(
                $command->organizationId,
                $command->eventId,
                array_values(array_unique([$command->actorUserId, $command->targetUserId])),
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

            $identity = $this->directory->findIdentity($command->targetUserId);
            $membership = $this->directory->findOrganizationMembership(
                $command->targetUserId,
                $command->organizationId,
            );
            $event = $this->directory->findEvent($command->eventId);

            if (null === $identity || !$identity->isActive()) {
                throw new AccessMutationFailed('Target identity is not active.');
            }
            if (null === $membership || !$membership->isActiveAt($command->occurredAt)) {
                throw new AccessMutationFailed('Target organization membership is not active.');
            }
            if (null === $event || $event->organizationId !== $command->organizationId) {
                throw new AccessMutationFailed('Event does not belong to the organization.');
            }
            $this->assertRoles($command->accessType, $command->roles);
            if (null !== $command->validFrom && null !== $command->validUntil && $command->validUntil < $command->validFrom) {
                throw new AccessMutationFailed('The assignment validity range is invalid.');
            }
            if (EventStatus::CLOSED === $event->status && EventAccessType::HISTORICAL !== $command->accessType) {
                throw new AccessMutationFailed('Closed events only accept historical assignments.');
            }
            if (EventStatus::CLOSED !== $event->status && EventAccessType::HISTORICAL === $command->accessType) {
                throw new AccessMutationFailed('Historical assignments require a closed event.');
            }

            $previous = $this->directory->findEventAssignment(
                $command->targetUserId,
                $command->organizationId,
                $command->eventId,
            );
            $membershipId = $this->assignments->save(
                $command->eventMembershipId,
                $command->targetUserId,
                $command->organizationId,
                $command->eventId,
                $command->accessType,
                $command->roles,
                $command->actorUserId,
                $command->occurredAt,
                $command->validFrom,
                $command->validUntil,
            );

            $this->auditLog->append(new AuditEntry(
                $command->auditLogId,
                $command->organizationId,
                $command->eventId,
                $command->actorUserId,
                'identity.event_access_assigned',
                'event_membership',
                $membershipId,
                $command->requestId,
                [
                    'target_user_id' => $command->targetUserId,
                    'previous' => null === $previous ? null : [
                        'access_type' => $previous->accessType->value,
                        'roles' => array_map(static fn ($role): string => $role->value, $previous->roles),
                    ],
                    'current' => [
                        'access_type' => $command->accessType->value,
                        'roles' => array_map(static fn ($role): string => $role->value, $command->roles),
                    ],
                ],
                $command->occurredAt,
            ));

            return $membershipId;
        });
    }

    /** @param list<\App\Identity\Domain\EventRole> $roles */
    private function assertRoles(EventAccessType $accessType, array $roles): void
    {
        $roleCodes = array_map(static fn ($role): string => $role->value, $roles);
        if ([] === $roleCodes || count($roleCodes) !== count(array_unique($roleCodes))) {
            throw new AccessMutationFailed('At least one unique event role is required.');
        }

        $historicalRole = \App\Identity\Domain\EventRole::HISTORICAL_VIEWER->value;
        if (EventAccessType::HISTORICAL === $accessType && [$historicalRole] !== $roleCodes) {
            throw new AccessMutationFailed('Historical access only accepts the historical viewer role.');
        }
        if (EventAccessType::OPERATIONAL === $accessType && in_array($historicalRole, $roleCodes, true)) {
            throw new AccessMutationFailed('Operational access cannot use the historical viewer role.');
        }
    }
}
