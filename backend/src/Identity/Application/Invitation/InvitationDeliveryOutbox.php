<?php

declare(strict_types=1);

namespace App\Identity\Application\Invitation;

interface InvitationDeliveryOutbox
{
    public function enqueue(
        string $deliveryId,
        string $invitationId,
        string $organizationId,
        ?string $eventId,
        string $recipient,
        string $tokenKeyVersion,
        \DateTimeImmutable $at,
    ): void;
}
