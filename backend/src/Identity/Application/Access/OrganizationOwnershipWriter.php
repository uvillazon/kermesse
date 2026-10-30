<?php

declare(strict_types=1);

namespace App\Identity\Application\Access;

use App\Identity\Domain\OrganizationAccessType;

interface OrganizationOwnershipWriter
{
    public function lockContext(string $organizationId, string $currentOwnerUserId, string $newOwnerUserId): void;

    public function transfer(
        string $organizationId,
        string $currentOwnerUserId,
        string $newOwnerUserId,
        OrganizationAccessType $previousOwnerAccessType,
        \DateTimeImmutable $at,
    ): OwnershipTransferResult;
}
