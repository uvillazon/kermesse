<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\Credential\UserCredentialRepository;
use Doctrine\DBAL\Connection;

final readonly class DoctrineUserCredentialRepository implements UserCredentialRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findByEmail(string $emailNormalized): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, email, display_name, status, password_hash FROM users WHERE email_normalized = ?',
            [$emailNormalized],
        );
        if (false === $row) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'status' => (string) $row['status'],
            'password_hash' => null === $row['password_hash'] || '' === $row['password_hash']
                ? null
                : (string) $row['password_hash'],
        ];
    }

    public function updatePasswordHash(string $userId, string $passwordHash, \DateTimeImmutable $at): void
    {
        $timestamp = $at->format('Y-m-d H:i:s');
        $this->connection->update('users', [
            'password_hash' => $passwordHash,
            'password_updated_at' => $timestamp,
            'updated_at' => $timestamp,
        ], ['id' => $userId]);
    }
}
