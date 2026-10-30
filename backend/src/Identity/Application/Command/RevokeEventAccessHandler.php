<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditLogWriter;
use App\Identity\Application\Access\AccessDirectory;
use App\Identity\Application\Access\EventAssignmentWriter;
use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\Permission;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;

final readonly class RevokeEventAccessHandler
{
    public function __construct(
        private TransactionalExecutor $transactions,
        private EventAssignmentWriter $assignments,
        private AccessDirectory $directory,
        private AuthorizationPolicy $authorization,
        private AuditLogWriter $auditLog,
    ) {
    }

    public function handle(RevokeEventAccessCommand $command): string
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

            $previous = $this->directory->findEventAssignment(
                $command->targetUserId,
                $command->organizationId,
                $command->eventId,
            );
            if (null === $previous || !$previous->isActiveAt($command->occurredAt)) {
                throw new AccessMutationFailed('Active event assignment does not exist.');
            }

            $membershipId = $this->assignments->revoke(
                $command->targetUserId,
                $command->organizationId,
                $command->eventId,
                $command->occurredAt,
            );
            $this->auditLog->append(new AuditEntry(
                $command->auditLogId,
                $command->organizationId,
                $command->eventId,
                $command->actorUserId,
                'identity.event_access_revoked',
                'event_membership',
                $membershipId,
                $command->requestId,
                [
                    'target_user_id' => $command->targetUserId,
                    'previous' => [
                        'access_type' => $previous->accessType->value,
                        'roles' => array_map(static fn (EventRole $role): string => $role->value, $previous->roles),
                    ],
                    'current_status' => 'revoked',
                ],
                $command->occurredAt,
            ));

            return $membershipId;
        });
    }
}
