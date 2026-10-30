<?php

declare(strict_types=1);

namespace App\Identity\Application\Access;

use App\Identity\Domain\OrganizationAccessType;

final readonly class OrganizationRevocationResult
{
    /** @param list<string> $eventMembershipIds */
    public function __construct(
        public string $organizationMembershipId,
        public OrganizationAccessType $previousAccessType,
        public array $eventMembershipIds,
        public int $revokedGrantCount,
    ) {
    }
}
