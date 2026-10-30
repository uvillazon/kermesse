<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;

final readonly class IdempotentExecutor
{
    public function __construct(
        private TransactionalExecutor $transactions,
        private IdempotencyRepository $repository,
    ) {
    }

    /** @param callable(): IdempotencyResponse $operation */
    public function execute(IdempotencyRequest $request, callable $operation): IdempotencyResponse
    {
        return $this->transactions->execute(function () use ($request, $operation): IdempotencyResponse {
            $stored = $this->repository->acquire($request);
            if (null !== $stored) {
                return $stored->asReplay();
            }

            $response = $operation();
            $this->repository->complete($request, $response, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            return $response;
        });
    }
}
