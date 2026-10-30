<?php

declare(strict_types=1);

namespace App\Identity\Application\Access;

interface OrganizationAccessWriter
{
    /** @param list<string> $userIds */
    public function lockContext(string $organizationId, string $targetUserId, array $userIds): void;

    public function revoke(
        string $userId,
        string $organizationId,
        \DateTimeImmutable $at,
    ): OrganizationRevocationResult;
}
