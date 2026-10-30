<?php

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\SecureInvitationTokenGenerator;
use PHPUnit\Framework\TestCase;

final class SecureInvitationTokenGeneratorTest extends TestCase
{
    public function testDerivesStableOpaqueTokenPerInvitationAndKeyVersion(): void
    {
        $generator = new SecureInvitationTokenGenerator(str_repeat('s', 32), 'v1');

        $first = $generator->generate('74000000-0000-4000-8000-000000000001');

        self::assertSame($first, $generator->generate('74000000-0000-4000-8000-000000000001'));
        self::assertNotSame($first, $generator->generate('74000000-0000-4000-8000-000000000002'));
        self::assertNotSame(
            $first,
            (new SecureInvitationTokenGenerator(str_repeat('s', 32), 'v2'))
                ->generate('74000000-0000-4000-8000-000000000001'),
        );
        self::assertSame('v1', $generator->keyVersion());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first);
    }

    public function testRetainsLegacyVersionDuringKeyRotation(): void
    {
        $invitationId = '74000000-0000-4000-8000-000000000001';
        $oldSecret = str_repeat('o', 32);
        $rotated = new SecureInvitationTokenGenerator(
            str_repeat('n', 32),
            'v2',
            ['v1' => $oldSecret],
        );
        $beforeRotation = (new SecureInvitationTokenGenerator($oldSecret, 'v1'))->generate($invitationId);

        self::assertSame($beforeRotation, $rotated->generate($invitationId, 'v1'));
        self::assertNotSame($beforeRotation, $rotated->generate($invitationId));
        self::assertSame('v2', $rotated->keyVersion());
    }

    public function testRejectsUnknownKeyVersion(): void
    {
        $generator = new SecureInvitationTokenGenerator(str_repeat('n', 32), 'v2');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not available: v1');
        $generator->generate('74000000-0000-4000-8000-000000000001', 'v1');
    }

    public function testRejectsActiveVersionDuplicatedInLegacyKeyring(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecureInvitationTokenGenerator(
            str_repeat('n', 32),
            'v2',
            ['v2' => str_repeat('o', 32)],
        );
    }
}
