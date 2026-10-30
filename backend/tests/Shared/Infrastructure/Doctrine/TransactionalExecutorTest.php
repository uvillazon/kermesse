<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class TransactionalExecutorTest extends TestCase
{
    public function testCommitsTheWholeOperation(): void
    {
        $this->requireSqlite();
        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
        $connection->executeStatement('CREATE TABLE values_store (id INTEGER PRIMARY KEY, value VARCHAR(20))');
        $executor = new TransactionalExecutor($connection);

        $executor->execute(function () use ($connection): void {
            $connection->insert('values_store', ['id' => 1, 'value' => 'committed']);
        });

        self::assertSame('committed', $connection->fetchOne('SELECT value FROM values_store WHERE id = 1'));
    }

    public function testRollsBackWhenTheOperationFails(): void
    {
        $this->requireSqlite();
        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
        $connection->executeStatement('CREATE TABLE values_store (id INTEGER PRIMARY KEY, value VARCHAR(20))');
        $executor = new TransactionalExecutor($connection);

        try {
            $executor->execute(function () use ($connection): void {
                $connection->insert('values_store', ['id' => 1, 'value' => 'rolled-back']);
                throw new \RuntimeException('failure');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('failure', $exception->getMessage());
        }

        self::assertFalse($connection->fetchOne('SELECT value FROM values_store WHERE id = 1'));
    }

    private function requireSqlite(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('La extensión PDO SQLite no está disponible en este entorno.');
        }
    }
}
