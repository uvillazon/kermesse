<?php

declare(strict_types=1);

namespace App\Tests\Identity\Application\Credential;

use App\Identity\Application\Credential\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'muy corta' => ['short12'];
        yield 'solo espacios' => [str_repeat(' ', 10)];
        yield 'excede 72 bytes' => [str_repeat('a', 73)];
        yield 'multibyte que excede 72 bytes' => [str_repeat('ñ', 37)];
    }

    #[DataProvider('rejected')]
    public function testRejectsPasswordsOutsidePolicy(string $password): void
    {
        self::assertNotNull(PasswordPolicy::violation($password));
    }

    public function testAcceptsPasswordsWithinPolicy(): void
    {
        self::assertNull(PasswordPolicy::violation('ocho1234'));
        self::assertNull(PasswordPolicy::violation(' con espacios '));
        self::assertNull(PasswordPolicy::violation(str_repeat('a', 72)));
    }
}
