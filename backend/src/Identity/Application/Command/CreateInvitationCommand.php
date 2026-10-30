<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\OrganizationAccessType;

final readonly class CreateInvitationCommand
{
    /** @param list<EventRole> $roles */
    public function __construct(
        public string $actorUserId,
        public string $organizationId,
        public ?string $eventId,
        public string $email,
        public OrganizationAccessType|EventAccessType $targetAccessType,
        public array $roles,
        public string $invitationId,
        public string $deliveryId,
        public string $auditLogId,
        public \DateTimeImmutable $expiresAt,
        public \DateTimeImmutable $occurredAt,
        public ?string $requestId = null,
    ) {
    }
}
