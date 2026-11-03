<?php

declare(strict_types=1);

namespace App\Sale\Application\CreateSale;

final readonly class SaleReservationPolicy
{
    public function __construct(private int $ttlSeconds)
    {
        if ($ttlSeconds < 60 || $ttlSeconds > 86400) {
            throw new \InvalidArgumentException('Sale reservation TTL must be between 60 and 86400 seconds.');
        }
    }

    public function expiresAt(\DateTimeImmutable $createdAt): \DateTimeImmutable
    {
        return $createdAt->modify('+'.$this->ttlSeconds.' seconds');
    }
}
