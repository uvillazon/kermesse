<?php

declare(strict_types=1);

namespace App\Sale\Domain;

final readonly class PricedSaleItem
{
    public function __construct(
        public string $productId,
        public string $productCodeSnapshot,
        public string $productNameSnapshot,
        public int $unitPriceMinorSnapshot,
        public int $quantity,
        public int $lineTotalMinor,
        public int $catalogVersion,
    ) {
    }
}
