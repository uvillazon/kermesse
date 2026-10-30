<?php

namespace App\Identity\Application\Access;

final readonly class AccessDecision
{
    private function __construct(
        public bool $allowed,
        public ?AccessDenialReason $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(AccessDenialReason $reason): self
    {
        return new self(false, $reason);
    }
}
