<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Domain\EventRole;

final readonly class ChangeEventRolesCommand
{
    /** @param list<EventRole> $roles */
    public function __construct(
        public string $actorUserId,
        public string $targetUserId,
        public string $organizationId,
        public string $eventId,
        public string $auditLogId,
        public array $roles,
        public \DateTimeImmutable $occurredAt,
        public ?string $requestId = null,
    ) {
    }
}
