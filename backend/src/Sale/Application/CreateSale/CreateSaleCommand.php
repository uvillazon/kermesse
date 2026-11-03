<?php

declare(strict_types=1);

namespace App\Sale\Application\CreateSale;

use App\Sale\Domain\RequestedSaleItem;

final readonly class CreateSaleCommand
{
    /** @param non-empty-list<RequestedSaleItem> $items */
    public function __construct(
        public string $organizationId,
        public string $eventId,
        public string $actorUserId,
        public string $buyerEmail,
        public ?string $buyerName,
        public ?string $buyerPhone,
        public string $paymentMethod,
        public string $channel,
        public array $items,
        public string $requestId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
