<?php

declare(strict_types=1);

namespace App\Identity\Application\Access;

use App\Identity\Domain\EventRole;

interface InvitationWriter
{
    public function lockContext(string $organizationId, ?string $eventId, string $actorUserId): void;

    /** @param list<EventRole> $roles */
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
    ): void;
}
