<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

interface IdempotencyRepository
{
    public function acquire(IdempotencyRequest $request): ?IdempotencyResponse;

    public function complete(IdempotencyRequest $request, IdempotencyResponse $response, \DateTimeImmutable $at): void;
}
