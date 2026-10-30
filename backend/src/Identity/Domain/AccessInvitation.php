<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final readonly class AccessInvitation
{
    /** @param list<EventRole> $roles */
    public function __construct(
        public string $id,
        public string $organizationId,
        public ?string $eventId,
        public string $emailNormalized,
        public string $targetAccessType,
        public InvitationStatus $status,
        public \DateTimeImmutable $expiresAt,
        public string $invitedBy,
        public array $roles = [],
    ) {
    }

    public function isPendingAt(\DateTimeImmutable $at): bool
    {
        return InvitationStatus::PENDING === $this->status && $at <= $this->expiresAt;
    }

    public function organizationAccessType(): OrganizationAccessType
    {
        return null === $this->eventId
            ? OrganizationAccessType::from($this->targetAccessType)
            : OrganizationAccessType::COLLABORATOR;
    }

    public function eventAccessType(): ?EventAccessType
    {
        return null === $this->eventId ? null : EventAccessType::from($this->targetAccessType);
    }
}
