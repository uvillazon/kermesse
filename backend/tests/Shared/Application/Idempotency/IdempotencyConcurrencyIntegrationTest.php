<?php

declare(strict_types=1);

namespace App\Tests\Shared\Application\Idempotency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class IdempotencyConcurrencyIntegrationTest extends TestCase
{
    private const USER_ID = '95000000-0000-4000-8000-000000000001';
    private const ORGANIZATION_ID = '96000000-0000-4000-8000-000000000001';
    private const EVENT_ID = '97000000-0000-4000-8000-000000000001';

    private ?Connection $connection = null;
    private string $url = '';

    protected function setUp(): void
    {
        $url = getenv('KERMESFERA_INTEGRATION_DATABASE_URL');
        if (false === $url || '' === $url) {
            self::markTestSkipped('Define KERMESFERA_INTEGRATION_DATABASE_URL para ejecutar integración contra MariaDB.');
        }
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open no está disponible para probar concurrencia real.');
        }

        $this->url = $url;
        $this->connection = self::connect($url);
        $this->removeFixture();
        $this->insertFixture();
    }

    protected function tearDown(): void
    {
        $this->removeFixture();
        $this->connection?->close();
    }

    public function testConcurrentDuplicateExecutesEffectOnlyOnceAndReplaysCommittedResponse(): void
    {
        $first = $this->startWorker('98000000-0000-4000-8000-000000000001', 900);
        $this->waitUntilAcquired($first['stderr']);
        $second = $this->startWorker('98000000-0000-4000-8000-000000000002', 0);

        $firstResult = $this->finishWorker($first);
        $secondResult = $this->finishWorker($second);

        self::assertFalse($firstResult['replayed']);
        self::assertTrue($firstResult['executed']);
        self::assertTrue($secondResult['replayed']);
        self::assertFalse($secondResult['executed']);
        self::assertSame($firstResult['status_code'], $secondResult['status_code']);
        self::assertSame($firstResult['body'], $secondResult['body']);
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM idempotency_keys
             WHERE organization_id = ? AND user_id = ? AND idempotency_key = ?',
            [self::ORGANIZATION_ID, self::USER_ID, 'concurrent-assignment-1'],
        ));
    }

    /** @return array{process: resource, stdout: resource, stderr: resource} */
    private function startWorker(string $recordId, int $holdMilliseconds): array
    {
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            dirname(__DIR__, 3).'/Fixtures/IdempotencyWorker.php',
            $this->url,
            $recordId,
            self::ORGANIZATION_ID,
            self::EVENT_ID,
            self::USER_ID,
            (string) $holdMilliseconds,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 4));
        self::assertIsResource($process);
        fclose($pipes[0]);

        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /** @param resource $stderr */
    private function waitUntilAcquired($stderr): void
    {
        stream_set_blocking($stderr, false);
        $deadline = microtime(true) + 5;
        $output = '';
        do {
            $output .= stream_get_contents($stderr);
            if (str_contains($output, 'ACQUIRED')) {
                stream_set_blocking($stderr, true);

                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail('The first idempotent operation did not acquire its row: '.$output);
    }

    /**
     * @param array{process: resource, stdout: resource, stderr: resource} $worker
     * @return array{status_code: int, body: array<string, mixed>, replayed: bool, executed: bool}
     */
    private function finishWorker(array $worker): array
    {
        $stdout = stream_get_contents($worker['stdout']);
        $stderr = stream_get_contents($worker['stderr']);
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        $exitCode = proc_close($worker['process']);
        self::assertSame(0, $exitCode, $stderr);

        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }

    private function insertFixture(): void
    {
        $now = '2026-10-30 14:00:00';
        $this->connection()->insert('users', [
            'id' => self::USER_ID,
            'email' => 'idempotency-concurrency@example.test',
            'email_normalized' => 'idempotency-concurrency@example.test',
            'display_name' => 'Idempotency Concurrency User',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->insert('organizations', [
            'id' => self::ORGANIZATION_ID,
            'name' => 'Idempotency Concurrency Organization',
            'slug' => 'idempotency-concurrency-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->insert('events', [
            'id' => self::EVENT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'name' => 'Idempotency Concurrency Event',
            'slug' => 'idempotency-concurrency-event',
            'starts_at' => null,
            'ends_at' => null,
            'status' => 'active',
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function removeFixture(): void
    {
        if (null === $this->connection) {
            return;
        }
        $this->connection->delete('idempotency_keys', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('events', ['id' => self::EVENT_ID]);
        $this->connection->delete('organizations', ['id' => self::ORGANIZATION_ID]);
        $this->connection->delete('users', ['id' => self::USER_ID]);
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
