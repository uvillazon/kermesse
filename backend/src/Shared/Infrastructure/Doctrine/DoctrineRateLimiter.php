<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\RateLimit\RateLimitDecision;
use App\Shared\Application\RateLimit\RateLimiter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class DoctrineRateLimiter implements RateLimiter
{
    public function __construct(private Connection $connection)
    {
    }

    public function consume(
        string $action,
        string $subject,
        int $limit,
        \DateTimeImmutable $now,
    ): RateLimitDecision {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Rate limit must be greater than zero.');
        }

        $windowStart = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);
        $windowEnd = $windowStart->modify('+1 minute');
        $bucketKey = hash('sha256', $action."\0".$subject."\0".$windowStart->format('U'));

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $hits = $this->incrementBucket($bucketKey, $windowStart, $windowEnd);
                $retryAfter = max(1, $windowEnd->getTimestamp() - $now->getTimestamp());

                return new RateLimitDecision(
                    $hits <= $limit,
                    $limit,
                    max(0, $limit - $hits),
                    $hits <= $limit ? 0 : $retryAfter,
                );
            } catch (UniqueConstraintViolationException $exception) {
                if (1 === $attempt) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Rate-limit bucket could not be updated.');
    }

    private function incrementBucket(
        string $bucketKey,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
    ): int {
        return $this->connection->transactional(function () use ($bucketKey, $windowStart, $windowEnd): int {
            $hits = $this->connection->fetchOne(
                'SELECT hit_count FROM api_rate_limit_buckets WHERE bucket_key = ? FOR UPDATE',
                [$bucketKey],
            );

            if (false === $hits) {
                $this->connection->insert('api_rate_limit_buckets', [
                    'bucket_key' => $bucketKey,
                    'window_started_at' => $windowStart,
                    'expires_at' => $windowEnd,
                    'hit_count' => 1,
                ], [
                    'window_started_at' => 'datetime_immutable',
                    'expires_at' => 'datetime_immutable',
                ]);

                return 1;
            }

            $next = (int) $hits + 1;
            $this->connection->update('api_rate_limit_buckets', ['hit_count' => $next], ['bucket_key' => $bucketKey]);

            return $next;
        });
    }
}
