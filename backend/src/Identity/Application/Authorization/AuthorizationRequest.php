<?php

namespace App\Identity\Application\Authorization;

use App\Identity\Domain\Permission;

final readonly class AuthorizationRequest
{
    public function __construct(
        public string $userId,
        public string $organizationId,
        public Permission $permission,
        public ?string $eventId = null,
        public ?\DateTimeImmutable $at = null,
    ) {
    }
}
