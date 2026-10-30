<?php

namespace App\Identity\Domain;

final readonly class UserIdentity
{
    public function __construct(
        public string $id,
        public IdentityStatus $status,
    ) {
    }

    public function isActive(): bool
    {
        return IdentityStatus::ACTIVE === $this->status;
    }
}
