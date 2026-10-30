<?php

namespace App\Shared\Infrastructure\RateLimit;

final class RateLimitPolicy
{
    public function __construct(
        private readonly bool $enabled,
        private readonly int $defaultPerMinute,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function defaultPerMinute(): int
    {
        return $this->defaultPerMinute;
    }
}
