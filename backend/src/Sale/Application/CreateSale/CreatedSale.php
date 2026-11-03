<?php

declare(strict_types=1);

namespace App\Sale\Application\CreateSale;

final readonly class CreatedSale
{
    /** @param array<string, mixed> $responseData */
    public function __construct(
        public string $saleId,
        public string $ticketNumber,
        public int $totalMinor,
        public string $currency,
        public array $responseData,
    ) {
    }
}
