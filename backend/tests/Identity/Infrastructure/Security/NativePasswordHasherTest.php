<?php

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\NativePasswordHasher;
use PHPUnit\Framework\TestCase;

final class NativePasswordHasherTest extends TestCase
{
    public function testHashesWithSaltAndVerifies(): void
    {
        $hasher = new NativePasswordHasher();

        $first = $hasher->hash('una contraseña segura');
        $second = $hasher->hash('una contraseña segura');

        self::assertNotSame($first, $second);
        self::assertStringNotContainsString('una contraseña segura', $first);
        self::assertTrue($hasher->verify('una contraseña segura', $first));
        self::assertFalse($hasher->verify('otra contraseña', $first));
        self::assertFalse($hasher->needsRehash($first));
    }

    public function testFlagsBcryptForRehashWhenArgon2idIsAvailable(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('PHP sin argon2id.');
        }

        self::assertTrue((new NativePasswordHasher())->needsRehash(password_hash('x', PASSWORD_BCRYPT)));
    }

    public function testDummyVerificationNeverThrows(): void
    {
        $hasher = new NativePasswordHasher();
        $hasher->verifyAgainstDummy('cualquier cosa');
        $hasher->verifyAgainstDummy('');

        $this->addToAssertionCount(1);
    }
}
