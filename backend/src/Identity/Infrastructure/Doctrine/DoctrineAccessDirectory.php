<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\Access\AccessDirectory;
use App\Identity\Domain\EventAccessContext;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventAssignment;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\EventStatus;
use App\Identity\Domain\IdentityStatus;
use App\Identity\Domain\MembershipStatus;
use App\Identity\Domain\OrganizationAccessType;
use App\Identity\Domain\OrganizationMembership;
use App\Identity\Domain\Permission;
use App\Identity\Domain\UserIdentity;
use Doctrine\DBAL\Connection;

final readonly class DoctrineAccessDirectory implements AccessDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    public function findIdentity(string $userId): ?UserIdentity
    {
        $row = $this->connection->fetchAssociative(
            'SELECT status FROM users WHERE id = ?',
            [$userId],
        );

        return false === $row ? null : new UserIdentity($userId, IdentityStatus::from((string) $row['status']));
    }

    public function findOrganizationMembership(string $userId, string $organizationId): ?OrganizationMembership
    {
        $row = $this->connection->fetchAssociative(
            'SELECT access_type, status, valid_from, valid_until
             FROM organization_memberships
             WHERE user_id = ? AND organization_id = ?',
            [$userId, $organizationId],
        );

        if (false === $row) {
            return null;
        }

        return new OrganizationMembership(
            $userId,
            $organizationId,
            OrganizationAccessType::from((string) $row['access_type']),
            MembershipStatus::from((string) $row['status']),
            $this->date($row['valid_from']),
            $this->date($row['valid_until']),
        );
    }

    public function findEvent(string $eventId): ?EventAccessContext
    {
        $row = $this->connection->fetchAssociative(
            'SELECT organization_id, status FROM events WHERE id = ?',
            [$eventId],
        );

        return false === $row ? null : new EventAccessContext(
            $eventId,
            (string) $row['organization_id'],
            EventStatus::from((string) $row['status']),
        );
    }

    public function findEventAssignment(string $userId, string $organizationId, string $eventId): ?EventAssignment
    {
        $row = $this->connection->fetchAssociative(
            'SELECT em.id, em.access_type, em.status, em.valid_from, em.valid_until
             FROM event_memberships em
             INNER JOIN events e ON e.id = em.event_id AND e.organization_id = ?
             WHERE em.user_id = ? AND em.event_id = ?',
            [$organizationId, $userId, $eventId],
        );

        if (false === $row) {
            return null;
        }

        $membershipId = (string) $row['id'];
        $roles = $this->findRoles($membershipId);
        $permissions = $this->findAdditionalPermissions($membershipId);

        return new EventAssignment(
            $userId,
            $organizationId,
            $eventId,
            EventAccessType::from((string) $row['access_type']),
            MembershipStatus::from((string) $row['status']),
            $this->date($row['valid_from']),
            $this->date($row['valid_until']),
            $roles,
            $permissions,
        );
    }

    /** @return list<EventRole> */
    private function findRoles(string $membershipId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT r.code FROM event_membership_roles mr
             INNER JOIN roles r ON r.id = mr.role_id
             WHERE mr.event_membership_id = ?',
            [$membershipId],
        );

        return array_values(array_map(static fn (string $code): EventRole => EventRole::from($code), $rows));
    }

    /** @return list<Permission> */
    private function findAdditionalPermissions(string $membershipId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT p.code FROM event_permission_grants g
             INNER JOIN permissions p ON p.id = g.permission_id
             WHERE g.event_membership_id = ? AND g.status = ?
               AND g.revoked_at IS NULL
               AND (g.valid_from IS NULL OR g.valid_from <= CURRENT_TIMESTAMP)
               AND (g.valid_until IS NULL OR g.valid_until >= CURRENT_TIMESTAMP)',
            [$membershipId, MembershipStatus::ACTIVE->value],
        );

        return array_values(array_map(static fn (string $code): Permission => Permission::from($code), $rows));
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable((string) $value);
    }
}
