<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventRole;

final readonly class AssignEventAccessCommand
{
    /** @param list<EventRole> $roles */
    public function __construct(
        public string $actorUserId,
        public string $targetUserId,
        public string $organizationId,
        public string $eventId,
        public string $eventMembershipId,
        public string $auditLogId,
        public EventAccessType $accessType,
        public array $roles,
        public \DateTimeImmutable $occurredAt,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validUntil = null,
        public ?string $requestId = null,
    ) {
    }
}
