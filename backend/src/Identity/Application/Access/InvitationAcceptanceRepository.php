<?php

declare(strict_types=1);

namespace App\Identity\Application\Access;

use App\Identity\Domain\AccessInvitation;
use App\Identity\Domain\OrganizationAccessType;

interface InvitationAcceptanceRepository
{
    public function lockByTokenHash(string $tokenHash): ?AccessInvitation;

    public function resolveIdentity(
        string $newUserId,
        string $emailNormalized,
        string $displayName,
        \DateTimeImmutable $at,
    ): string;

    public function activateOrganizationMembership(
        string $newMembershipId,
        string $userId,
        string $organizationId,
        OrganizationAccessType $accessType,
        bool $replaceAccessType,
        \DateTimeImmutable $at,
    ): string;

    public function markAccepted(string $invitationId, string $userId, \DateTimeImmutable $at): void;
}
