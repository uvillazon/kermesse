<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

final readonly class IdempotencyRequest
{
    public function __construct(
        public string $recordId,
        public string $organizationId,
        public ?string $eventId,
        public string $userId,
        public string $key,
        public string $method,
        public string $route,
        public string $requestHash,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
