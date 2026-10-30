<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

final readonly class IdempotencyResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public int $statusCode,
        public array $body,
        public bool $replayed = false,
    ) {
    }

    public function asReplay(): self
    {
        return new self($this->statusCode, $this->body, true);
    }
}
