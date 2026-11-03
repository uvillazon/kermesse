<?php

declare(strict_types=1);

namespace App\Sale\Application\CreateSale;

use App\Sale\Domain\CatalogOffer;
use App\Sale\Domain\PricedSale;

interface SaleWriteRepository
{
    public function assertOpenEvent(string $organizationId, string $eventId): void;

    /** @param non-empty-list<string> $productIds @return list<CatalogOffer> */
    public function lockCatalogOffers(string $eventId, array $productIds): array;

    public function createSale(
        CreateSaleCommand $command,
        PricedSale $priced,
        ?\DateTimeImmutable $reservationExpiresAt,
    ): CreatedSale;
}
