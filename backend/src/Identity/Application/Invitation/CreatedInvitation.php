<?php

declare(strict_types=1);

namespace App\Identity\Application\Invitation;

final readonly class CreatedInvitation
{
    public function __construct(
        public string $invitationId,
        public string $deliveryId,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
