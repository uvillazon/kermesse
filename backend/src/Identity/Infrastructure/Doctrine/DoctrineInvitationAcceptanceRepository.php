<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\Access\InvitationAcceptanceRepository;
use App\Identity\Application\Command\AccessMutationFailed;
use App\Identity\Domain\AccessInvitation;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\IdentityStatus;
use App\Identity\Domain\InvitationStatus;
use App\Identity\Domain\MembershipStatus;
use App\Identity\Domain\OrganizationAccessType;
use Doctrine\DBAL\Connection;

final readonly class DoctrineInvitationAcceptanceRepository implements InvitationAcceptanceRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function lockByTokenHash(string $tokenHash): ?AccessInvitation
    {
        $row = $this->connection->fetchAssociative(
            'SELECT i.id, i.organization_id, i.event_id, i.email_normalized,
                    i.target_access_type, i.status, i.expires_at, i.invited_by,
                    i.roles_json, o.status AS organization_status
             FROM access_invitations i
             INNER JOIN organizations o ON o.id = i.organization_id
             WHERE i.token_hash = ? FOR UPDATE',
            [$tokenHash],
        );
        if (false === $row) {
            return null;
        }
        if ('active' !== $row['organization_status']) {
            throw new AccessMutationFailed('Invitation organization is not active.');
        }

        $roleCodes = null === $row['roles_json']
            ? []
            : json_decode((string) $row['roles_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($roleCodes)) {
            throw new AccessMutationFailed('Invitation roles are invalid.');
        }

        return new AccessInvitation(
            (string) $row['id'],
            (string) $row['organization_id'],
            null === $row['event_id'] ? null : (string) $row['event_id'],
            (string) $row['email_normalized'],
            (string) $row['target_access_type'],
            InvitationStatus::from((string) $row['status']),
            new \DateTimeImmutable((string) $row['expires_at']),
            (string) $row['invited_by'],
            array_values(array_map(static fn (string $code): EventRole => EventRole::from($code), $roleCodes)),
        );
    }

    public function resolveIdentity(
        string $newUserId,
        string $emailNormalized,
        string $displayName,
        \DateTimeImmutable $at,
    ): string {
        $row = $this->connection->fetchAssociative(
            'SELECT id, status FROM users WHERE email_normalized = ? FOR UPDATE',
            [$emailNormalized],
        );
        if (false !== $row) {
            if (IdentityStatus::ACTIVE->value !== $row['status']) {
                throw new AccessMutationFailed('Existing identity is not active.');
            }

            return (string) $row['id'];
        }

        $timestamp = $at->format('Y-m-d H:i:s');
        $this->connection->insert('users', [
            'id' => $newUserId,
            'email' => $emailNormalized,
            'email_normalized' => $emailNormalized,
            'display_name' => $displayName,
            'status' => IdentityStatus::ACTIVE->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $newUserId;
    }

    public function activateOrganizationMembership(
        string $newMembershipId,
        string $userId,
        string $organizationId,
        OrganizationAccessType $accessType,
        bool $replaceAccessType,
        \DateTimeImmutable $at,
    ): string {
        $row = $this->connection->fetchAssociative(
            'SELECT id, access_type FROM organization_memberships
             WHERE user_id = ? AND organization_id = ? FOR UPDATE',
            [$userId, $organizationId],
        );
        $timestamp = $at->format('Y-m-d H:i:s');
        if (false === $row) {
            $membershipId = $newMembershipId;
            $this->connection->insert('organization_memberships', [
                'id' => $membershipId,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'access_type' => $accessType->value,
                'status' => MembershipStatus::ACTIVE->value,
                'valid_from' => null,
                'valid_until' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        } else {
            $membershipId = (string) $row['id'];
            $accessType = $replaceAccessType
                ? $accessType
                : OrganizationAccessType::from((string) $row['access_type']);
            $this->connection->update('organization_memberships', [
                'access_type' => $accessType->value,
                'status' => MembershipStatus::ACTIVE->value,
                'valid_from' => null,
                'valid_until' => null,
                'updated_at' => $timestamp,
            ], ['id' => $membershipId]);
        }

        if ($replaceAccessType) {
            $this->syncOrganizationRole($membershipId, $userId, $accessType, $at);
        }

        return $membershipId;
    }

    public function markAccepted(string $invitationId, string $userId, \DateTimeImmutable $at): void
    {
        $changed = $this->connection->update('access_invitations', [
            'status' => InvitationStatus::ACCEPTED->value,
            'accepted_by' => $userId,
            'accepted_at' => $at->format('Y-m-d H:i:s'),
        ], [
            'id' => $invitationId,
            'status' => InvitationStatus::PENDING->value,
        ]);
        if (1 !== $changed) {
            throw new AccessMutationFailed('Invitation was already consumed.');
        }
    }

    private function syncOrganizationRole(
        string $membershipId,
        string $userId,
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
            'granted_by' => $userId,
            'granted_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }
}
