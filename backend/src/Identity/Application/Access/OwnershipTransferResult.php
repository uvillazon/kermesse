<?php

declare(strict_types=1);

namespace App\Identity\Application\Access;

use App\Identity\Domain\OrganizationAccessType;

final readonly class OwnershipTransferResult
{
    public function __construct(
        public string $previousOwnerMembershipId,
        public string $newOwnerMembershipId,
        public OrganizationAccessType $newOwnerPreviousAccessType,
        public OrganizationAccessType $previousOwnerCurrentAccessType,
    ) {
    }
}
