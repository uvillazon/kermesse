<?php

declare(strict_types=1);

namespace App\Sale\Domain;

final readonly class RequestedSaleItem
{
    public function __construct(
        public string $productId,
        public int $quantity,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Sale item quantity must be greater than zero.');
        }
    }
}
