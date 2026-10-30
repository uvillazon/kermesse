<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\Access\EventAssignmentWriter;
use App\Identity\Application\Command\AccessMutationFailed;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\MembershipStatus;
use Doctrine\DBAL\Connection;

final readonly class DoctrineEventAssignmentWriter implements EventAssignmentWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function lockContext(string $organizationId, string $eventId, array $userIds): void
    {
        $event = $this->connection->fetchOne(
            'SELECT id FROM events WHERE id = ? AND organization_id = ? FOR UPDATE',
            [$eventId, $organizationId],
        );
        if (false === $event) {
            throw new AccessMutationFailed('Event does not belong to the organization.');
        }

        foreach ($userIds as $userId) {
            $this->connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $this->connection->fetchOne(
                'SELECT id FROM organization_memberships WHERE organization_id = ? AND user_id = ? FOR UPDATE',
                [$organizationId, $userId],
            );
        }
    }

    public function save(
        string $newMembershipId,
        string $userId,
        string $organizationId,
        string $eventId,
        EventAccessType $accessType,
        array $roles,
        string $grantedBy,
        \DateTimeImmutable $at,
        ?\DateTimeImmutable $validFrom = null,
        ?\DateTimeImmutable $validUntil = null,
    ): string {
        $membershipId = $this->connection->fetchOne(
            'SELECT em.id FROM event_memberships em
             INNER JOIN events e ON e.id = em.event_id AND e.organization_id = ?
             WHERE em.event_id = ? AND em.user_id = ? FOR UPDATE',
            [$organizationId, $eventId, $userId],
        );
        $values = [
            'access_type' => $accessType->value,
            'status' => MembershipStatus::ACTIVE->value,
            'valid_from' => $validFrom?->format('Y-m-d H:i:s'),
            'valid_until' => $validUntil?->format('Y-m-d H:i:s'),
            'updated_at' => $at->format('Y-m-d H:i:s'),
        ];

        if (false === $membershipId) {
            $membershipId = $newMembershipId;
            $this->connection->insert('event_memberships', [
                'id' => $membershipId,
                'event_id' => $eventId,
                'user_id' => $userId,
                ...$values,
                'created_at' => $at->format('Y-m-d H:i:s'),
            ]);
        } else {
            $membershipId = (string) $membershipId;
            $this->connection->update('event_memberships', $values, ['id' => $membershipId]);
        }

        $this->writeRoles((string) $membershipId, $roles, $grantedBy, $at);

        return (string) $membershipId;
    }

    public function replaceRoles(
        string $userId,
        string $organizationId,
        string $eventId,
        array $roles,
        string $grantedBy,
        \DateTimeImmutable $at,
    ): string {
        $membershipId = $this->membershipId($userId, $organizationId, $eventId);
        $this->writeRoles($membershipId, $roles, $grantedBy, $at);
        $this->connection->update('event_memberships', [
            'updated_at' => $at->format('Y-m-d H:i:s'),
        ], ['id' => $membershipId]);

        return $membershipId;
    }

    public function revoke(
        string $userId,
        string $organizationId,
        string $eventId,
        \DateTimeImmutable $at,
    ): string {
        $membershipId = $this->membershipId($userId, $organizationId, $eventId);
        $timestamp = $at->format('Y-m-d H:i:s');
        $this->connection->update('event_memberships', [
            'status' => MembershipStatus::REVOKED->value,
            'updated_at' => $timestamp,
        ], ['id' => $membershipId]);
        $this->connection->executeStatement(
            'UPDATE event_permission_grants
             SET status = ?, revoked_at = ?
             WHERE event_membership_id = ? AND status = ? AND revoked_at IS NULL',
            [MembershipStatus::REVOKED->value, $timestamp, $membershipId, MembershipStatus::ACTIVE->value],
        );

        return $membershipId;
    }

    /** @param list<EventRole> $roles */
    private function writeRoles(
        string $membershipId,
        array $roles,
        string $grantedBy,
        \DateTimeImmutable $at,
    ): void {
        $this->connection->delete('event_membership_roles', ['event_membership_id' => $membershipId]);
        foreach ($roles as $role) {
            $roleId = $this->connection->fetchOne(
                'SELECT id FROM roles WHERE code = ? AND scope = ?',
                [$role->value, 'event'],
            );
            if (false === $roleId) {
                throw new AccessMutationFailed('Unknown event role: '.$role->value);
            }
            $this->connection->insert('event_membership_roles', [
                'event_membership_id' => $membershipId,
                'role_id' => $roleId,
                'granted_by' => $grantedBy,
                'granted_at' => $at->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function membershipId(string $userId, string $organizationId, string $eventId): string
    {
        $membershipId = $this->connection->fetchOne(
            'SELECT em.id FROM event_memberships em
             INNER JOIN events e ON e.id = em.event_id AND e.organization_id = ?
             WHERE em.event_id = ? AND em.user_id = ? FOR UPDATE',
            [$organizationId, $eventId, $userId],
        );
        if (false === $membershipId) {
            throw new AccessMutationFailed('Event assignment does not exist.');
        }

        return (string) $membershipId;
    }
}
