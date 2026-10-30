<?php

namespace App\Identity\Domain;

final readonly class OrganizationMembership
{
    public function __construct(
        public string $userId,
        public string $organizationId,
        public OrganizationAccessType $accessType,
        public MembershipStatus $status,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validUntil = null,
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
