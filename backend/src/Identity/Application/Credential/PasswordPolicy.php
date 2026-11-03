<?php

declare(strict_types=1);

namespace App\Identity\Application\Credential;

final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    // bcrypt ignora lo que pasa de 72 bytes; se rechaza para que la contraseña completa cuente.
    public const MAX_BYTES = 72;

    /** Devuelve el motivo del rechazo, o null si la contraseña es aceptable. */
    public static function violation(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return sprintf('password must have at least %d characters.', self::MIN_LENGTH);
        }
        if (strlen($password) > self::MAX_BYTES) {
            return sprintf('password cannot exceed %d bytes.', self::MAX_BYTES);
        }
        if ('' === trim($password)) {
            return 'password cannot be blank.';
        }

        return null;
    }
}
