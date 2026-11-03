<?php

declare(strict_types=1);

namespace App\Sale\Application\ExpireReservations;

interface SaleReservationRepository
{
    /** @return list<ExpiredReservation> */
    public function expireDue(string $eventId, \DateTimeImmutable $now): array;
}
