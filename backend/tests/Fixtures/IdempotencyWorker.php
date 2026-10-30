<?php

declare(strict_types=1);

use App\Shared\Application\Idempotency\CanonicalRequestHasher;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotencyResponse;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Infrastructure\Doctrine\DoctrineIdempotencyRepository;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (7 !== $argc) {
    fwrite(STDERR, "Expected URL, record id, organization id, event id, user id and hold milliseconds.\n");
    exit(2);
}

[$script, $url, $recordId, $organizationId, $eventId, $userId, $holdMilliseconds] = $argv;
$parts = parse_url($url);
if (!is_array($parts)) {
    fwrite(STDERR, "Invalid database URL.\n");
    exit(2);
}

$connection = DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => $parts['host'] ?? '127.0.0.1',
    'port' => $parts['port'] ?? 3306,
    'user' => $parts['user'] ?? null,
    'password' => $parts['pass'] ?? null,
    'dbname' => ltrim($parts['path'] ?? '', '/'),
    'charset' => 'utf8mb4',
    'serverVersion' => getenv('DATABASE_SERVER_VERSION') ?: '10.4.18-MariaDB',
]);
$createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$request = new IdempotencyRequest(
    $recordId,
    $organizationId,
    $eventId,
    $userId,
    'concurrent-assignment-1',
    'POST',
    '/api/events/'.$eventId.'/access',
    (new CanonicalRequestHasher())->hash(['roles' => ['seller']]),
    $createdAt,
    $createdAt->modify('+24 hours'),
);
$executed = false;

try {
    $response = (new IdempotentExecutor(
        new TransactionalExecutor($connection),
        new DoctrineIdempotencyRepository($connection),
    ))->execute($request, static function () use (&$executed, $holdMilliseconds): IdempotencyResponse {
        $executed = true;
        if ((int) $holdMilliseconds > 0) {
            fwrite(STDERR, "ACQUIRED\n");
            usleep((int) $holdMilliseconds * 1000);
        }

        return new IdempotencyResponse(201, ['assignment_id' => 'concurrent-a-1']);
    });

    echo json_encode([
        'status_code' => $response->statusCode,
        'body' => $response->body,
        'replayed' => $response->replayed,
        'executed' => $executed,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
} finally {
    $connection->close();
}
