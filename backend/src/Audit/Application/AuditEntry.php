<?php

declare(strict_types=1);

namespace App\Audit\Application;

final readonly class AuditEntry
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $organizationId,
        public ?string $eventId,
        public ?string $actorUserId,
        public string $action,
        public string $aggregateType,
        public ?string $aggregateId,
        public ?string $requestId,
        public array $metadata,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
