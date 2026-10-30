<?php

declare(strict_types=1);

namespace App\Shared\Application\RateLimit;

interface RateLimiter
{
    public function consume(
        string $action,
        string $subject,
        int $limit,
        \DateTimeImmutable $now,
    ): RateLimitDecision;
}
