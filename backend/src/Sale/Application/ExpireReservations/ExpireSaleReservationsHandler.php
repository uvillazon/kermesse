<?php

declare(strict_types=1);

namespace App\Sale\Application\ExpireReservations;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditLogWriter;
use App\Shared\Application\Identifier\IdentifierGenerator;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;

final readonly class ExpireSaleReservationsHandler
{
    public function __construct(
        private TransactionalExecutor $transactions,
        private SaleReservationRepository $reservations,
        private AuditLogWriter $auditLog,
        private IdentifierGenerator $identifiers,
    ) {
    }

    public function handle(string $eventId, \DateTimeImmutable $now): int
    {
        return $this->transactions->execute(function () use ($eventId, $now): int {
            $expired = $this->reservations->expireDue($eventId, $now);
            foreach ($expired as $reservation) {
                $this->auditLog->append(new AuditEntry(
                    $this->identifiers->generate(),
                    $reservation->organizationId,
                    $reservation->eventId,
                    null,
                    'sale.reservation_expired',
                    'sale',
                    $reservation->saleId,
                    null,
                    [
                        'payment_id' => $reservation->paymentId,
                        'sale_status' => 'cancelled',
                        'payment_status' => 'cancelled',
                    ],
                    $now,
                ));
            }

            return count($expired);
        });
    }
}
