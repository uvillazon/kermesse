<?php

declare(strict_types=1);

namespace App\Tests\Shared\Application\Idempotency;

use App\Shared\Application\Idempotency\CanonicalRequestHasher;
use App\Shared\Application\Idempotency\IdempotencyConflict;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotencyResponse;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Infrastructure\Doctrine\DoctrineIdempotencyRepository;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class IdempotentExecutorIntegrationTest extends TestCase
{
    private const USER_ID = '91000000-0000-4000-8000-000000000001';
    private const ORGANIZATION_ID = '92000000-0000-4000-8000-000000000001';
    private const EVENT_ID = '93000000-0000-4000-8000-000000000001';

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $url = getenv('KERMESFERA_INTEGRATION_DATABASE_URL');
        if (false === $url || '' === $url) {
            self::markTestSkipped('Define KERMESFERA_INTEGRATION_DATABASE_URL para ejecutar integración contra MariaDB.');
        }

        $this->connection = self::connect($url);
        $this->connection->setNestTransactionsWithSavepoints(true);
        $this->connection->beginTransaction();
        $this->insertFixture();
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection?->close();
    }

    public function testReplaysStoredResponseWithoutExecutingOperationTwice(): void
    {
        $executions = 0;
        $operation = static function () use (&$executions): IdempotencyResponse {
            ++$executions;

            return new IdempotencyResponse(201, ['assignment_id' => 'a-1']);
        };

        $first = $this->executor()->execute($this->request('94000000-0000-4000-8000-000000000001'), $operation);
        $second = $this->executor()->execute($this->request('94000000-0000-4000-8000-000000000002'), $operation);

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->statusCode, $second->statusCode);
        self::assertSame($first->body, $second->body);
        self::assertSame(1, $executions);
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM idempotency_keys WHERE organization_id = ? AND user_id = ? AND idempotency_key = ?',
            [self::ORGANIZATION_ID, self::USER_ID, 'assign-access-1'],
        ));
    }

    public function testRejectsReusingKeyWithDifferentPayload(): void
    {
        $this->executor()->execute(
            $this->request('94000000-0000-4000-8000-000000000003'),
            static fn (): IdempotencyResponse => new IdempotencyResponse(201, ['assignment_id' => 'a-1']),
        );

        $this->expectException(IdempotencyConflict::class);
        $this->expectExceptionMessage('different request payload');
        $this->executor()->execute(
            $this->request('94000000-0000-4000-8000-000000000004', ['roles' => ['scanner']]),
            static fn (): IdempotencyResponse => new IdempotencyResponse(201, ['assignment_id' => 'a-2']),
        );
    }

    public function testFailedOperationRollsBackReservationAndCanBeRetried(): void
    {
        try {
            $this->executor()->execute(
                $this->request('94000000-0000-4000-8000-000000000005'),
                static function (): never {
                    throw new \RuntimeException('simulated failure');
                },
            );
            self::fail('Expected operation to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated failure', $exception->getMessage());
        }

        self::assertSame(0, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM idempotency_keys WHERE id = ?',
            ['94000000-0000-4000-8000-000000000005'],
        ));

        $response = $this->executor()->execute(
            $this->request('94000000-0000-4000-8000-000000000006'),
            static fn (): IdempotencyResponse => new IdempotencyResponse(201, ['assignment_id' => 'a-retry']),
        );

        self::assertFalse($response->replayed);
        self::assertSame(['assignment_id' => 'a-retry'], $response->body);
    }

    /** @param array<string, mixed> $payload */
    private function request(string $recordId, array $payload = ['roles' => ['seller']]): IdempotencyRequest
    {
        $createdAt = new \DateTimeImmutable('2026-10-30 15:00:00', new \DateTimeZone('UTC'));

        return new IdempotencyRequest(
            $recordId,
            self::ORGANIZATION_ID,
            self::EVENT_ID,
            self::USER_ID,
            'assign-access-1',
            'POST',
            '/api/events/'.self::EVENT_ID.'/access',
            (new CanonicalRequestHasher())->hash($payload),
            $createdAt,
            $createdAt->modify('+24 hours'),
        );
    }

    private function executor(): IdempotentExecutor
    {
        $connection = $this->connection();

        return new IdempotentExecutor(
            new TransactionalExecutor($connection),
            new DoctrineIdempotencyRepository($connection),
        );
    }

    private function insertFixture(): void
    {
        $now = '2026-10-30 14:00:00';
        $this->connection()->insert('users', [
            'id' => self::USER_ID,
            'email' => 'idempotency@example.test',
            'email_normalized' => 'idempotency@example.test',
            'display_name' => 'Idempotency User',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->insert('organizations', [
            'id' => self::ORGANIZATION_ID,
            'name' => 'Idempotency Organization',
            'slug' => 'idempotency-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->insert('events', [
            'id' => self::EVENT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'name' => 'Idempotency Event',
            'slug' => 'idempotency-event',
            'starts_at' => null,
            'ends_at' => null,
            'status' => 'active',
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }

    private static function connect(string $url): Connection
    {
        $parts = parse_url($url);
        self::assertIsArray($parts);

        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? 3306,
            'user' => $parts['user'] ?? null,
            'password' => $parts['pass'] ?? null,
            'dbname' => ltrim($parts['path'] ?? '', '/'),
            'charset' => 'utf8mb4',
            'serverVersion' => getenv('DATABASE_SERVER_VERSION') ?: '10.4.18-MariaDB',
        ]);
    }
}
