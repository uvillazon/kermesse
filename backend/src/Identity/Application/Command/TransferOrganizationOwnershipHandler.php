<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditLogWriter;
use App\Identity\Application\Access\AccessDirectory;
use App\Identity\Application\Access\OrganizationOwnershipWriter;
use App\Identity\Domain\OrganizationAccessType;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;

final readonly class TransferOrganizationOwnershipHandler
{
    public function __construct(
        private TransactionalExecutor $transactions,
        private OrganizationOwnershipWriter $ownership,
        private AccessDirectory $directory,
        private AuditLogWriter $auditLog,
    ) {
    }

    public function handle(TransferOrganizationOwnershipCommand $command): string
    {
        return $this->transactions->execute(function () use ($command): string {
            if ($command->currentOwnerUserId === $command->newOwnerUserId) {
                throw new AccessMutationFailed('New owner must be a different user.');
            }
            if (OrganizationAccessType::OWNER === $command->previousOwnerAccessType) {
                throw new AccessMutationFailed('Previous owner must leave the owner role after transfer.');
            }

            $this->ownership->lockContext(
                $command->organizationId,
                $command->currentOwnerUserId,
                $command->newOwnerUserId,
            );
            $currentOwner = $this->directory->findOrganizationMembership(
                $command->currentOwnerUserId,
                $command->organizationId,
            );
            $newOwner = $this->directory->findOrganizationMembership(
                $command->newOwnerUserId,
                $command->organizationId,
            );
            if (
                null === $currentOwner
                || !$currentOwner->isActiveAt($command->occurredAt)
                || OrganizationAccessType::OWNER !== $currentOwner->accessType
            ) {
                throw new AccessAuthorizationDenied('Only the active organization owner can transfer ownership.');
            }
            if (null === $newOwner || !$newOwner->isActiveAt($command->occurredAt)) {
                throw new AccessMutationFailed('New owner must have an active organization membership.');
            }

            $result = $this->ownership->transfer(
                $command->organizationId,
                $command->currentOwnerUserId,
                $command->newOwnerUserId,
                $command->previousOwnerAccessType,
                $command->occurredAt,
            );
            $this->auditLog->append(new AuditEntry(
                $command->auditLogId,
                $command->organizationId,
                null,
                $command->currentOwnerUserId,
                'identity.organization_ownership_transferred',
                'organization',
                $command->organizationId,
                $command->requestId,
                [
                    'previous_owner_user_id' => $command->currentOwnerUserId,
                    'new_owner_user_id' => $command->newOwnerUserId,
                    'previous_owner_membership_id' => $result->previousOwnerMembershipId,
                    'new_owner_membership_id' => $result->newOwnerMembershipId,
                    'new_owner_previous_access_type' => $result->newOwnerPreviousAccessType->value,
                    'previous_owner_current_access_type' => $result->previousOwnerCurrentAccessType->value,
                ],
                $command->occurredAt,
            ));

            return $result->newOwnerMembershipId;
        });
    }
}
