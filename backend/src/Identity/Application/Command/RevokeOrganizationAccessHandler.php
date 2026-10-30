<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditLogWriter;
use App\Identity\Application\Access\AccessDirectory;
use App\Identity\Application\Access\OrganizationAccessWriter;
use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Domain\OrganizationAccessType;
use App\Identity\Domain\Permission;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;

final readonly class RevokeOrganizationAccessHandler
{
    public function __construct(
        private TransactionalExecutor $transactions,
        private OrganizationAccessWriter $organizations,
        private AccessDirectory $directory,
        private AuthorizationPolicy $authorization,
        private AuditLogWriter $auditLog,
    ) {
    }

    public function handle(RevokeOrganizationAccessCommand $command): string
    {
        return $this->transactions->execute(function () use ($command): string {
            if ($command->actorUserId === $command->targetUserId) {
                throw new AccessMutationFailed('Self-revocation requires a separate ownership transfer flow.');
            }

            $this->organizations->lockContext(
                $command->organizationId,
                $command->targetUserId,
                [$command->actorUserId, $command->targetUserId],
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

            $membership = $this->directory->findOrganizationMembership(
                $command->targetUserId,
                $command->organizationId,
            );
            if (null === $membership || !$membership->isActiveAt($command->occurredAt)) {
                throw new AccessMutationFailed('Active organization membership does not exist.');
            }
            if (OrganizationAccessType::OWNER === $membership->accessType) {
                throw new AccessMutationFailed('Organization owner must be transferred before revocation.');
            }

            $result = $this->organizations->revoke(
                $command->targetUserId,
                $command->organizationId,
                $command->occurredAt,
            );
            $this->auditLog->append(new AuditEntry(
                $command->auditLogId,
                $command->organizationId,
                null,
                $command->actorUserId,
                'identity.organization_access_revoked',
                'organization_membership',
                $result->organizationMembershipId,
                $command->requestId,
                [
                    'target_user_id' => $command->targetUserId,
                    'previous_access_type' => $result->previousAccessType->value,
                    'revoked_event_membership_ids' => $result->eventMembershipIds,
                    'revoked_grant_count' => $result->revokedGrantCount,
                    'current_status' => 'revoked',
                ],
                $command->occurredAt,
            ));

            return $result->organizationMembershipId;
        });
    }
}
