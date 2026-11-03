<?php

declare(strict_types=1);

namespace App\Sale\Domain;

final class SaleRuleViolation extends \DomainException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
