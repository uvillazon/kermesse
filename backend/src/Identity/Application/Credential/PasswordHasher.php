<?php

declare(strict_types=1);

namespace App\Identity\Application\Credential;

interface PasswordHasher
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, string $hash): bool;

    public function needsRehash(string $hash): bool;

    /**
     * Gasta el mismo tiempo que una verificación real cuando la cuenta no existe
     * o no tiene contraseña, para no revelar por tiempo de respuesta si el correo existe.
     */
    public function verifyAgainstDummy(string $plainPassword): void;
}
