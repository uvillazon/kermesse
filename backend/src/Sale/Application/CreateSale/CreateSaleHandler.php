<?php

declare(strict_types=1);

namespace App\Sale\Application\CreateSale;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditLogWriter;
use App\Sale\Domain\SalePricer;
use App\Sale\Domain\SaleRuleViolation;
use App\Shared\Application\Identifier\IdentifierGenerator;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;

final readonly class CreateSaleHandler
{
    public function __construct(
        private TransactionalExecutor $transactions,
        private SaleWriteRepository $sales,
        private SalePricer $pricer,
        private AuditLogWriter $auditLog,
        private IdentifierGenerator $identifiers,
        private SaleReservationPolicy $reservationPolicy,
    ) {
    }

    public function handle(CreateSaleCommand $command): CreatedSale
    {
        if (!in_array($command->paymentMethod, ['cash', 'qr_transfer'], true)) {
            throw new SaleCreationFailed('Unsupported payment method.');
        }

        return $this->transactions->execute(function () use ($command): CreatedSale {
            $this->sales->assertOpenEvent($command->organizationId, $command->eventId);
            $productIds = array_values(array_unique(array_map(
                static fn ($item): string => $item->productId,
                $command->items,
            )));
            $offers = $this->sales->lockCatalogOffers($command->eventId, $productIds);

            try {
                $priced = $this->pricer->price($offers, $command->items);
            } catch (SaleRuleViolation $violation) {
                throw new SaleCreationFailed($violation->getMessage(), previous: $violation);
            }

            $reservationExpiresAt = 'cash' === $command->paymentMethod
                ? null
                : $this->reservationPolicy->expiresAt($command->occurredAt);
            $created = $this->sales->createSale($command, $priced, $reservationExpiresAt);
            $this->auditLog->append(new AuditEntry(
                $this->identifiers->generate(),
                $command->organizationId,
                $command->eventId,
                $command->actorUserId,
                'sale.created',
                'sale',
                $created->saleId,
                $command->requestId,
                [
                    'total_minor' => $created->totalMinor,
                    'currency' => $created->currency,
                    'payment_method' => $command->paymentMethod,
                    'status' => null === $reservationExpiresAt ? 'confirmed' : 'pending_payment',
                    'ticket_number' => $created->ticketNumber,
                    'reservation_expires_at' => $reservationExpiresAt?->format(DATE_ATOM),
                ],
                $command->occurredAt,
            ));

            return $created;
        });
    }
}
