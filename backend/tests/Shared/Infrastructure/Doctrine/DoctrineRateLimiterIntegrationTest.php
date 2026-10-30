<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\DoctrineRateLimiter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class DoctrineRateLimiterIntegrationTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $url = getenv('KERMESFERA_INTEGRATION_DATABASE_URL');
        if (false === $url || '' === $url) {
            self::markTestSkipped('Define KERMESFERA_INTEGRATION_DATABASE_URL para ejecutar integración contra MariaDB.');
        }

        $parts = parse_url($url);
        self::assertIsArray($parts);
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? 3306,
            'user' => $parts['user'] ?? null,
            'password' => $parts['pass'] ?? null,
            'dbname' => ltrim($parts['path'] ?? '', '/'),
            'charset' => 'utf8mb4',
            'serverVersion' => getenv('DATABASE_SERVER_VERSION') ?: '10.4.18-MariaDB',
        ]);
        $this->connection->setNestTransactionsWithSavepoints(true);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection?->close();
    }

    public function testRejectsOnlyAfterLimitAndResetsInNextWindow(): void
    {
        $limiter = new DoctrineRateLimiter($this->connection());
        $now = new \DateTimeImmutable('2026-10-30T15:42:10+00:00');

        $first = $limiter->consume('POST:sales', 'user:one', 2, $now);
        $second = $limiter->consume('POST:sales', 'user:one', 2, $now->modify('+5 seconds'));
        $third = $limiter->consume('POST:sales', 'user:one', 2, $now->modify('+10 seconds'));
        $nextWindow = $limiter->consume('POST:sales', 'user:one', 2, $now->modify('+1 minute'));

        self::assertTrue($first->allowed);
        self::assertSame(1, $first->remaining);
        self::assertTrue($second->allowed);
        self::assertSame(0, $second->remaining);
        self::assertFalse($third->allowed);
        self::assertSame(40, $third->retryAfterSeconds);
        self::assertTrue($nextWindow->allowed);
        self::assertSame(1, $nextWindow->remaining);
    }

    private function connection(): Connection
    {
        return $this->connection ?? throw new \LogicException('Connection is not initialized.');
    }
}
