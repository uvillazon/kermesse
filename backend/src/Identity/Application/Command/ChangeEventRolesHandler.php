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
use App\Identity\Domain\EventRole;
use App\Identity\Domain\Permission;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;

final readonly class ChangeEventRolesHandler
{
    public function __construct(
        private TransactionalExecutor $transactions,
        private EventAssignmentWriter $assignments,
        private AccessDirectory $directory,
        private AuthorizationPolicy $authorization,
        private AuditLogWriter $auditLog,
    ) {
    }

    public function handle(ChangeEventRolesCommand $command): string
    {
        return $this->transactions->execute(function () use ($command): string {
            $this->assignments->lockContext(
                $command->organizationId,
                $command->eventId,
                array_values(array_unique([$command->actorUserId, $command->targetUserId])),
            );
            $this->assertAuthorized($command);

            $previous = $this->directory->findEventAssignment(
                $command->targetUserId,
                $command->organizationId,
                $command->eventId,
            );
            if (null === $previous || !$previous->isActiveAt($command->occurredAt)) {
                throw new AccessMutationFailed('Active event assignment does not exist.');
            }
            $this->assertRoles($previous->accessType, $command->roles);

            $membershipId = $this->assignments->replaceRoles(
                $command->targetUserId,
                $command->organizationId,
                $command->eventId,
                $command->roles,
                $command->actorUserId,
                $command->occurredAt,
            );
            $this->auditLog->append(new AuditEntry(
                $command->auditLogId,
                $command->organizationId,
                $command->eventId,
                $command->actorUserId,
                'identity.event_roles_changed',
                'event_membership',
                $membershipId,
                $command->requestId,
                [
                    'target_user_id' => $command->targetUserId,
                    'previous_roles' => array_map(static fn (EventRole $role): string => $role->value, $previous->roles),
                    'current_roles' => array_map(static fn (EventRole $role): string => $role->value, $command->roles),
                ],
                $command->occurredAt,
            ));

            return $membershipId;
        });
    }

    private function assertAuthorized(ChangeEventRolesCommand $command): void
    {
        $decision = $this->authorization->decide(new AuthorizationRequest(
            $command->actorUserId,
            $command->organizationId,
            Permission::ORGANIZATION_MANAGE_MEMBERS,
            at: $command->occurredAt,
        ));
        if (!$decision->allowed) {
            throw new AccessAuthorizationDenied('Actor not authorized: '.$decision->reason?->value);
        }
    }

    /** @param list<EventRole> $roles */
    private function assertRoles(EventAccessType $accessType, array $roles): void
    {
        $roleCodes = array_map(static fn (EventRole $role): string => $role->value, $roles);
        if ([] === $roleCodes || count($roleCodes) !== count(array_unique($roleCodes))) {
            throw new AccessMutationFailed('At least one unique event role is required.');
        }

        if (EventAccessType::HISTORICAL === $accessType && [EventRole::HISTORICAL_VIEWER->value] !== $roleCodes) {
            throw new AccessMutationFailed('Historical access only accepts the historical viewer role.');
        }
        if (EventAccessType::OPERATIONAL === $accessType && in_array(EventRole::HISTORICAL_VIEWER->value, $roleCodes, true)) {
            throw new AccessMutationFailed('Operational access cannot use the historical viewer role.');
        }
    }
}
