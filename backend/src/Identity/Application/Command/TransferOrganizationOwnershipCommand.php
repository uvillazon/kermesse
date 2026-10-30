<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Domain\OrganizationAccessType;

final readonly class TransferOrganizationOwnershipCommand
{
    public function __construct(
        public string $currentOwnerUserId,
        public string $newOwnerUserId,
        public string $organizationId,
        public OrganizationAccessType $previousOwnerAccessType,
        public string $auditLogId,
        public \DateTimeImmutable $occurredAt,
        public ?string $requestId = null,
    ) {
    }
}
