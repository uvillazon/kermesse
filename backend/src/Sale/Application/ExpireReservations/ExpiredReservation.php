<?php

declare(strict_types=1);

namespace App\Sale\Application\ExpireReservations;

final readonly class ExpiredReservation
{
    public function __construct(
        public string $saleId,
        public string $organizationId,
        public string $eventId,
        public string $paymentId,
    ) {
    }
}
