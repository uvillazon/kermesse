<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\Access\OrganizationAccessWriter;
use App\Identity\Application\Access\OrganizationRevocationResult;
use App\Identity\Application\Command\AccessMutationFailed;
use App\Identity\Domain\MembershipStatus;
use App\Identity\Domain\OrganizationAccessType;
use Doctrine\DBAL\Connection;

final readonly class DoctrineOrganizationAccessWriter implements OrganizationAccessWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function lockContext(string $organizationId, string $targetUserId, array $userIds): void
    {
        $organization = $this->connection->fetchOne(
            'SELECT id FROM organizations WHERE id = ? AND status = ? FOR UPDATE',
            [$organizationId, 'active'],
        );
        if (false === $organization) {
            throw new AccessMutationFailed('Organization is not active.');
        }

        foreach (array_values(array_unique($userIds)) as $userId) {
            $this->connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $this->connection->fetchOne(
                'SELECT id FROM organization_memberships WHERE organization_id = ? AND user_id = ? FOR UPDATE',
                [$organizationId, $userId],
            );
        }
        $this->connection->fetchFirstColumn(
            'SELECT em.id FROM event_memberships em
             INNER JOIN events e ON e.id = em.event_id
             WHERE e.organization_id = ? AND em.user_id = ? FOR UPDATE',
            [$organizationId, $targetUserId],
        );
        $this->connection->fetchFirstColumn(
            'SELECT g.id FROM event_permission_grants g
             INNER JOIN event_memberships em ON em.id = g.event_membership_id
             INNER JOIN events e ON e.id = em.event_id
             WHERE e.organization_id = ? AND em.user_id = ? FOR UPDATE',
            [$organizationId, $targetUserId],
        );
    }

    public function revoke(
        string $userId,
        string $organizationId,
        \DateTimeImmutable $at,
    ): OrganizationRevocationResult {
        $membership = $this->connection->fetchAssociative(
            'SELECT id, access_type FROM organization_memberships
             WHERE organization_id = ? AND user_id = ? FOR UPDATE',
            [$organizationId, $userId],
        );
        if (false === $membership) {
            throw new AccessMutationFailed('Organization membership does not exist.');
        }

        $eventMembershipIds = array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            $this->connection->fetchFirstColumn(
                'SELECT em.id FROM event_memberships em
                 INNER JOIN events e ON e.id = em.event_id
                 WHERE e.organization_id = ? AND em.user_id = ? FOR UPDATE',
                [$organizationId, $userId],
            ),
        ));
        $timestamp = $at->format('Y-m-d H:i:s');
        $revokedGrantCount = 0;
        foreach ($eventMembershipIds as $eventMembershipId) {
            $revokedGrantCount += $this->connection->executeStatement(
                'UPDATE event_permission_grants
                 SET status = ?, revoked_at = ?
                 WHERE event_membership_id = ? AND status = ? AND revoked_at IS NULL',
                [MembershipStatus::REVOKED->value, $timestamp, $eventMembershipId, MembershipStatus::ACTIVE->value],
            );
            $this->connection->update('event_memberships', [
                'status' => MembershipStatus::REVOKED->value,
                'updated_at' => $timestamp,
            ], ['id' => $eventMembershipId]);
        }
        $this->connection->update('organization_memberships', [
            'status' => MembershipStatus::REVOKED->value,
            'updated_at' => $timestamp,
        ], ['id' => $membership['id']]);

        return new OrganizationRevocationResult(
            (string) $membership['id'],
            OrganizationAccessType::from((string) $membership['access_type']),
            $eventMembershipIds,
            $revokedGrantCount,
        );
    }
}
