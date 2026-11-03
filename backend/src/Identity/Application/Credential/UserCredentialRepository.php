<?php

declare(strict_types=1);

namespace App\Identity\Application\Credential;

interface UserCredentialRepository
{
    /**
     * @return array{id: string, email: string, display_name: string, status: string, password_hash: ?string}|null
     */
    public function findByEmail(string $emailNormalized): ?array;

    public function updatePasswordHash(string $userId, string $passwordHash, \DateTimeImmutable $at): void;
}
