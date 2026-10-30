<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class AcceptInvitationCommand
{
    public function __construct(
        public string $token,
        public string $email,
        public string $displayName,
        public string $newUserId,
        public string $newOrganizationMembershipId,
        public ?string $newEventMembershipId,
        public string $auditLogId,
        public \DateTimeImmutable $occurredAt,
        public ?string $requestId = null,
    ) {
    }
}
