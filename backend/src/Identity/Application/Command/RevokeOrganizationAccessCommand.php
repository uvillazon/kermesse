<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class RevokeOrganizationAccessCommand
{
    public function __construct(
        public string $actorUserId,
        public string $targetUserId,
        public string $organizationId,
        public string $auditLogId,
        public \DateTimeImmutable $occurredAt,
        public ?string $requestId = null,
    ) {
    }
}
