<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\Access\OrganizationOwnershipWriter;
use App\Identity\Application\Access\OwnershipTransferResult;
use App\Identity\Application\Command\AccessMutationFailed;
use App\Identity\Domain\MembershipStatus;
use App\Identity\Domain\OrganizationAccessType;
use Doctrine\DBAL\Connection;

final readonly class DoctrineOrganizationOwnershipWriter implements OrganizationOwnershipWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function lockContext(string $organizationId, string $currentOwnerUserId, string $newOwnerUserId): void
    {
        $organization = $this->connection->fetchOne(
            'SELECT id FROM organizations WHERE id = ? AND status = ? FOR UPDATE',
            [$organizationId, 'active'],
        );
        if (false === $organization) {
            throw new AccessMutationFailed('Organization is not active.');
        }

        $userIds = [$currentOwnerUserId, $newOwnerUserId];
        sort($userIds);
        foreach ($userIds as $userId) {
            $this->connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $this->connection->fetchOne(
                'SELECT id FROM organization_memberships WHERE organization_id = ? AND user_id = ? FOR UPDATE',
                [$organizationId, $userId],
            );
        }
    }

    public function transfer(
        string $organizationId,
        string $currentOwnerUserId,
        string $newOwnerUserId,
        OrganizationAccessType $previousOwnerAccessType,
        \DateTimeImmutable $at,
    ): OwnershipTransferResult {
        $currentOwner = $this->membership($organizationId, $currentOwnerUserId);
        $newOwner = $this->membership($organizationId, $newOwnerUserId);
        if (
            MembershipStatus::ACTIVE->value !== $currentOwner['status']
            || OrganizationAccessType::OWNER->value !== $currentOwner['access_type']
        ) {
            throw new AccessMutationFailed('Current owner membership changed before transfer.');
        }
        if (MembershipStatus::ACTIVE->value !== $newOwner['status']) {
            throw new AccessMutationFailed('New owner membership changed before transfer.');
        }

        $timestamp = $at->format('Y-m-d H:i:s');
        $this->connection->update('organization_memberships', [
            'access_type' => OrganizationAccessType::OWNER->value,
            'updated_at' => $timestamp,
        ], ['id' => $newOwner['id']]);
        $this->syncRole((string) $newOwner['id'], $currentOwnerUserId, OrganizationAccessType::OWNER, $at);

        $this->connection->update('organization_memberships', [
            'access_type' => $previousOwnerAccessType->value,
            'updated_at' => $timestamp,
        ], ['id' => $currentOwner['id']]);
        $this->syncRole((string) $currentOwner['id'], $currentOwnerUserId, $previousOwnerAccessType, $at);

        $ownerCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM organization_memberships
             WHERE organization_id = ? AND status = ? AND access_type = ?',
            [$organizationId, MembershipStatus::ACTIVE->value, OrganizationAccessType::OWNER->value],
        );
        if ($ownerCount < 1) {
            throw new AccessMutationFailed('Organization must retain at least one active owner.');
        }

        return new OwnershipTransferResult(
            (string) $currentOwner['id'],
            (string) $newOwner['id'],
            OrganizationAccessType::from((string) $newOwner['access_type']),
            $previousOwnerAccessType,
        );
    }

    /** @return array{id: string, access_type: string, status: string} */
    private function membership(string $organizationId, string $userId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, access_type, status FROM organization_memberships
             WHERE organization_id = ? AND user_id = ? FOR UPDATE',
            [$organizationId, $userId],
        );
        if (false === $row) {
            throw new AccessMutationFailed('Organization membership does not exist.');
        }

        return [
            'id' => (string) $row['id'],
            'access_type' => (string) $row['access_type'],
            'status' => (string) $row['status'],
        ];
    }

    private function syncRole(
        string $membershipId,
        string $grantedBy,
        OrganizationAccessType $accessType,
        \DateTimeImmutable $at,
    ): void {
        $this->connection->delete('organization_membership_roles', [
            'organization_membership_id' => $membershipId,
        ]);
        $roleCode = match ($accessType) {
            OrganizationAccessType::OWNER => 'ORG_OWNER',
            OrganizationAccessType::ADMINISTRATOR => 'ORG_ADMIN',
            OrganizationAccessType::COLLABORATOR => null,
        };
        if (null === $roleCode) {
            return;
        }

        $roleId = $this->connection->fetchOne(
            'SELECT id FROM roles WHERE code = ? AND scope = ?',
            [$roleCode, 'organization'],
        );
        if (false === $roleId) {
            throw new AccessMutationFailed('Unknown organization role: '.$roleCode);
        }
        $this->connection->insert('organization_membership_roles', [
            'organization_membership_id' => $membershipId,
            'role_id' => $roleId,
            'granted_by' => $grantedBy,
            'granted_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }
}
