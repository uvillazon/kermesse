<?php

declare(strict_types=1);

namespace App\Sale\Domain;

final readonly class PricedSale
{
    /** @param non-empty-list<PricedSaleItem> $items */
    public function __construct(
        public array $items,
        public string $currency,
        public int $subtotalMinor,
        public int $discountTotalMinor,
        public int $totalMinor,
    ) {
    }
}
