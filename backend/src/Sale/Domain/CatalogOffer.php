<?php

declare(strict_types=1);

namespace App\Sale\Domain;

final readonly class CatalogOffer
{
    public function __construct(
        public string $productId,
        public string $code,
        public string $name,
        public int $unitPriceMinor,
        public string $currency,
        public int $availableQuantity,
        public bool $active,
        public int $version,
    ) {
        if ($unitPriceMinor < 0 || $availableQuantity < 0 || $version < 1) {
            throw new \InvalidArgumentException('Catalog offer values are invalid.');
        }
        if (1 !== preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException('Currency must use an ISO-like three-letter code.');
        }
    }
}
