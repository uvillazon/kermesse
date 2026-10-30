<?php

declare(strict_types=1);

namespace App\Shared\Application\RateLimit;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfterSeconds,
    ) {
    }
}
