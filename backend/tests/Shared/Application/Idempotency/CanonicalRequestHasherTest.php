<?php

declare(strict_types=1);

namespace App\Tests\Shared\Application\Idempotency;

use App\Shared\Application\Idempotency\CanonicalRequestHasher;
use PHPUnit\Framework\TestCase;

final class CanonicalRequestHasherTest extends TestCase
{
    public function testAssociativeKeyOrderDoesNotChangeHash(): void
    {
        $hasher = new CanonicalRequestHasher();

        self::assertSame(
            $hasher->hash(['roles' => ['seller', 'scanner'], 'user' => ['id' => 'u-1', 'active' => true]]),
            $hasher->hash(['user' => ['active' => true, 'id' => 'u-1'], 'roles' => ['seller', 'scanner']]),
        );
    }

    public function testListOrderChangesHash(): void
    {
        $hasher = new CanonicalRequestHasher();

        self::assertNotSame(
            $hasher->hash(['roles' => ['seller', 'scanner']]),
            $hasher->hash(['roles' => ['scanner', 'seller']]),
        );
    }
}
