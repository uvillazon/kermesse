<?php

namespace App\Identity\Application\Access;

final readonly class AccessRequest
{
    public function __construct(
        public string $userId,
        public string $organizationId,
        public AccessIntent $intent,
        public ?string $eventId = null,
        public ?\DateTimeImmutable $at = null,
    ) {
    }

    public function evaluatedAt(): \DateTimeImmutable
    {
        return $this->at ?? new \DateTimeImmutable();
    }
}
