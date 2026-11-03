<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\Credential\PasswordHasher;

/**
 * Usa password_hash() de PHP: argon2id si la extensión está disponible, si no bcrypt.
 * Los hashes guardan su propio algoritmo, así que needsRehash() migra cuentas al iniciar sesión.
 */
final class NativePasswordHasher implements PasswordHasher
{
    private ?string $dummyHash = null;

    public function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, $this->algorithm());
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm());
    }

    public function verifyAgainstDummy(string $plainPassword): void
    {
        $this->dummyHash ??= password_hash(random_bytes(16), $this->algorithm());
        password_verify($plainPassword, $this->dummyHash);
    }

    private function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
