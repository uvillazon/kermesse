<?php

namespace App\Identity\Domain;

final readonly class EventAssignment
{
    public function __construct(
        public string $userId,
        public string $organizationId,
        public string $eventId,
        public EventAccessType $accessType,
        public MembershipStatus $status,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validUntil = null,
        /** @var list<EventRole> */
        public array $roles = [],
        /** @var list<Permission> */
        public array $additionalPermissions = [],
    ) {
    }

    public function isActiveAt(\DateTimeImmutable $at): bool
    {
        if (MembershipStatus::ACTIVE !== $this->status) {
            return false;
        }

        if (null !== $this->validFrom && $at < $this->validFrom) {
            return false;
        }

        return null === $this->validUntil || $at <= $this->validUntil;
    }
}
