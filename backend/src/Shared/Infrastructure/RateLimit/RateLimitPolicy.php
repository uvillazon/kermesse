<?php

namespace App\Shared\Infrastructure\RateLimit;

final class RateLimitPolicy
{
    public const LOGIN_ROUTE = 'api_v1_auth_login';

    public function __construct(
        private readonly bool $enabled,
        private readonly int $defaultPerMinute,
        private readonly int $loginPerMinute = 10,
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

    /** Cuota por IP y por cuenta para POST /auth/login, más estricta que la general. */
    public function loginPerMinute(): int
    {
        return $this->loginPerMinute;
    }

    public function perMinuteFor(?string $route): int
    {
        return self::LOGIN_ROUTE === $route ? $this->loginPerMinute : $this->defaultPerMinute;
    }
}
