<?php

declare(strict_types=1);

namespace App\Identity\Application\Access;

use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventRole;

interface EventAssignmentWriter
{
    /** @param list<string> $userIds */
    public function lockContext(string $organizationId, string $eventId, array $userIds): void;

    /** @param list<EventRole> $roles */
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
    ): string;

    /** @param list<EventRole> $roles */
    public function replaceRoles(
        string $userId,
        string $organizationId,
        string $eventId,
        array $roles,
        string $grantedBy,
        \DateTimeImmutable $at,
    ): string;

    public function revoke(
        string $userId,
        string $organizationId,
        string $eventId,
        \DateTimeImmutable $at,
    ): string;
}
