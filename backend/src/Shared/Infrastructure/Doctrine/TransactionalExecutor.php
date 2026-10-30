<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;

final readonly class TransactionalExecutor
{
    public function __construct(private Connection $connection)
    {
    }

    /** @template T */
    /** @param callable(): T $operation @return T */
    public function execute(callable $operation): mixed
    {
        return $this->connection->transactional($operation);
    }
}
