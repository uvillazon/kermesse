<?php

declare(strict_types=1);

namespace App\Identity\Application\Invitation;

final readonly class PendingInvitationDelivery
{
    public function __construct(
        public string $deliveryId,
        public string $invitationId,
        public string $recipient,
        public string $tokenKeyVersion,
        public string $lockToken,
        public int $attempt,
    ) {
    }
}
