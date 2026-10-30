<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

final class IdempotencyConflict extends \DomainException
{
}
