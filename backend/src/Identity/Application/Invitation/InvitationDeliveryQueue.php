<?php

declare(strict_types=1);

namespace App\Identity\Application\Invitation;

interface InvitationDeliveryQueue
{
    public function claim(
        string $lockToken,
        \DateTimeImmutable $at,
        \DateTimeImmutable $staleBefore,
    ): ?PendingInvitationDelivery;

    public function markSent(
        string $deliveryId,
        string $lockToken,
        string $providerReference,
        \DateTimeImmutable $at,
    ): void;

    public function releaseForRetry(
        string $deliveryId,
        string $lockToken,
        string $error,
        \DateTimeImmutable $nextAttemptAt,
    ): void;
}
