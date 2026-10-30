<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\Access\InvitationWriter;
use App\Identity\Application\Command\AccessMutationFailed;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\InvitationStatus;
use Doctrine\DBAL\Connection;

final readonly class DoctrineInvitationWriter implements InvitationWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function lockContext(string $organizationId, ?string $eventId, string $actorUserId): void
    {
        $organization = $this->connection->fetchOne(
            'SELECT id FROM organizations WHERE id = ? AND status = ? FOR UPDATE',
            [$organizationId, 'active'],
        );
        if (false === $organization) {
            throw new AccessMutationFailed('Organization is not active.');
        }

        $this->connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$actorUserId]);
        $this->connection->fetchOne(
            'SELECT id FROM organization_memberships WHERE organization_id = ? AND user_id = ? FOR UPDATE',
            [$organizationId, $actorUserId],
        );

        if (null !== $eventId) {
            $event = $this->connection->fetchOne(
                'SELECT id FROM events WHERE id = ? AND organization_id = ? FOR UPDATE',
                [$eventId, $organizationId],
            );
            if (false === $event) {
                throw new AccessMutationFailed('Invitation event does not belong to the organization.');
            }
        }
    }

    public function create(
        string $invitationId,
        string $organizationId,
        ?string $eventId,
        string $emailNormalized,
        string $targetAccessType,
        array $roles,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        string $invitedBy,
        \DateTimeImmutable $createdAt,
    ): void {
        $existing = $this->connection->fetchOne(
            'SELECT id FROM access_invitations
             WHERE organization_id = ?
               AND (event_id = ? OR (event_id IS NULL AND ? IS NULL))
               AND email_normalized = ? AND status = ? AND expires_at >= ?
             FOR UPDATE',
            [
                $organizationId,
                $eventId,
                $eventId,
                $emailNormalized,
                InvitationStatus::PENDING->value,
                $createdAt->format('Y-m-d H:i:s'),
            ],
        );
        if (false !== $existing) {
            throw new AccessMutationFailed('A pending invitation already exists for this scope and email.');
        }

        $this->connection->insert('access_invitations', [
            'id' => $invitationId,
            'organization_id' => $organizationId,
            'event_id' => $eventId,
            'email_normalized' => $emailNormalized,
            'target_access_type' => $targetAccessType,
            'roles_json' => [] === $roles ? null : json_encode(
                array_map(static fn (EventRole $role): string => $role->value, $roles),
                JSON_THROW_ON_ERROR,
            ),
            'status' => InvitationStatus::PENDING->value,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'invited_by' => $invitedBy,
            'accepted_by' => null,
            'accepted_at' => null,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);
    }
}
