<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\Idempotency\IdempotencyConflict;
use App\Shared\Application\Idempotency\IdempotencyRepository;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotencyResponse;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class DoctrineIdempotencyRepository implements IdempotencyRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function acquire(IdempotencyRequest $request): ?IdempotencyResponse
    {
        try {
            $this->insert($request);

            return null;
        } catch (UniqueConstraintViolationException) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, request_hash, state, status_code, response_json, expires_at
                 FROM idempotency_keys
                 WHERE organization_id = ? AND user_id = ? AND idempotency_key = ? AND method = ? AND route = ?
                 FOR UPDATE',
                [
                    $request->organizationId,
                    $request->userId,
                    $request->key,
                    strtoupper($request->method),
                    $request->route,
                ],
            );
            if (false === $row) {
                throw new IdempotencyConflict('Idempotency identifier conflicts with another record.');
            }
            if ((string) $row['request_hash'] !== $request->requestHash) {
                throw new IdempotencyConflict('Idempotency key was already used with a different request payload.');
            }
            if (new \DateTimeImmutable((string) $row['expires_at']) < $request->createdAt) {
                $this->connection->delete('idempotency_keys', ['id' => $row['id']]);
                $this->insert($request);

                return null;
            }
            if ('completed' !== $row['state']) {
                throw new IdempotencyConflict('An operation with this idempotency key is still in progress.');
            }

            $body = json_decode((string) $row['response_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($body)) {
                throw new IdempotencyConflict('Stored idempotency response is invalid.');
            }

            return new IdempotencyResponse((int) $row['status_code'], $body, true);
        }
    }

    public function complete(IdempotencyRequest $request, IdempotencyResponse $response, \DateTimeImmutable $at): void
    {
        $changed = $this->connection->update('idempotency_keys', [
            'state' => 'completed',
            'status_code' => $response->statusCode,
            'response_json' => json_encode($response->body, JSON_THROW_ON_ERROR),
            'completed_at' => $at->format('Y-m-d H:i:s'),
        ], [
            'id' => $request->recordId,
            'state' => 'processing',
        ]);
        if (1 !== $changed) {
            throw new IdempotencyConflict('Idempotency record could not be completed.');
        }
    }

    private function insert(IdempotencyRequest $request): void
    {
        if ('' === $request->key || trim($request->key) !== $request->key || strlen($request->key) > 128) {
            throw new IdempotencyConflict('Idempotency key must contain between 1 and 128 characters.');
        }
        if ('' === $request->method || strlen($request->method) > 12) {
            throw new IdempotencyConflict('HTTP method must contain between 1 and 12 characters.');
        }
        if ('' === $request->route || strlen($request->route) > 255) {
            throw new IdempotencyConflict('Route must contain between 1 and 255 characters.');
        }
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $request->requestHash)) {
            throw new IdempotencyConflict('Request hash must be a lowercase SHA-256 value.');
        }
        if ($request->expiresAt <= $request->createdAt) {
            throw new IdempotencyConflict('Idempotency expiry must be in the future.');
        }

        $this->connection->insert('idempotency_keys', [
            'id' => $request->recordId,
            'organization_id' => $request->organizationId,
            'event_id' => $request->eventId,
            'user_id' => $request->userId,
            'idempotency_key' => $request->key,
            'method' => strtoupper($request->method),
            'route' => $request->route,
            'request_hash' => $request->requestHash,
            'state' => 'processing',
            'status_code' => null,
            'response_json' => null,
            'created_at' => $request->createdAt->format('Y-m-d H:i:s'),
            'completed_at' => null,
            'expires_at' => $request->expiresAt->format('Y-m-d H:i:s'),
        ]);
    }
}
