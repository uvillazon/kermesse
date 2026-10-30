<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Shared\Infrastructure\Security\HmacBearerTokenCodec;
use App\Shared\Infrastructure\Security\InvalidBearerToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HmacBearerTokenCodecTest extends TestCase
{
    private const SECRET = 'unit-test-auth-secret-with-at-least-32-characters';
    private const SUBJECT = 'a1000000-0000-4000-8000-000000000001';

    public function testIssuesAndValidatesSignedToken(): void
    {
        $now = new \DateTimeImmutable('2026-10-30T20:00:00+00:00');
        $token = $this->codec()->issue(self::SUBJECT, $now, 300, ['jti' => 'test-token']);

        self::assertSame(self::SUBJECT, $this->codec()->subject($token, $now->modify('+1 minute')));
    }

    public function testRejectsModifiedSignature(): void
    {
        $now = new \DateTimeImmutable('2026-10-30T20:00:00+00:00');
        $token = $this->codec()->issue(self::SUBJECT, $now, 300);
        $token[strlen($token) - 1] = 'A' === $token[strlen($token) - 1] ? 'B' : 'A';

        $this->expectException(InvalidBearerToken::class);
        $this->codec()->subject($token, $now);
    }

    public function testRejectsExpiredToken(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-10-30T20:00:00+00:00');
        $token = $this->codec()->issue(self::SUBJECT, $issuedAt, 60);

        $this->expectException(InvalidBearerToken::class);
        $this->codec()->subject($token, $issuedAt->modify('+61 seconds'));
    }

    public function testRejectsWrongIssuerOrAudience(): void
    {
        $now = new \DateTimeImmutable('2026-10-30T20:00:00+00:00');
        $token = (new HmacBearerTokenCodec(self::SECRET, 'other-issuer', 'kermesfera-api', 0, 3600))
            ->issue(self::SUBJECT, $now, 60);

        $this->expectException(InvalidBearerToken::class);
        $this->codec()->subject($token, $now);
    }

    #[DataProvider('invalidTokenProvider')]
    public function testRejectsMalformedTokens(string $token): void
    {
        $this->expectException(InvalidBearerToken::class);
        $this->codec()->subject($token, new \DateTimeImmutable('2026-10-30T20:00:00+00:00'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokenProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'two sections' => ['header.payload'];
        yield 'invalid characters' => ['header.pay+load.signature'];
        yield 'invalid json' => ['bm90LWpzb24.bm90LWpzb24.c2lnbmF0dXJl'];
    }

    private function codec(): HmacBearerTokenCodec
    {
        return new HmacBearerTokenCodec(self::SECRET, 'kermesfera', 'kermesfera-api', 0, 3600);
    }
}
