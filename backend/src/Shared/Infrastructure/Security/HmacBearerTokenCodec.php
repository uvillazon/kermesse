<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

final readonly class HmacBearerTokenCodec
{
    public function __construct(
        private string $secret,
        private string $issuer,
        private string $audience,
        private int $clockSkewSeconds,
        private int $maximumLifetimeSeconds,
    ) {
        if (strlen($this->secret) < 32) {
            throw new \InvalidArgumentException('Bearer token secret must contain at least 32 characters.');
        }
        if ('' === trim($this->issuer) || '' === trim($this->audience)) {
            throw new \InvalidArgumentException('Bearer token issuer and audience are required.');
        }
        if ($this->clockSkewSeconds < 0 || $this->maximumLifetimeSeconds < 1) {
            throw new \InvalidArgumentException('Bearer token timing configuration is invalid.');
        }
    }

    public function subject(string $token, \DateTimeImmutable $now): string
    {
        $parts = explode('.', $token);
        if (3 !== count($parts) || in_array('', $parts, true)) {
            throw new InvalidBearerToken('Malformed bearer token.');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        $header = $this->json($this->decode($encodedHeader));
        $claims = $this->json($this->decode($encodedClaims));
        $signature = $this->decode($encodedSignature);
        $expected = hash_hmac('sha256', $encodedHeader.'.'.$encodedClaims, $this->secret, true);

        if (!hash_equals($expected, $signature)) {
            throw new InvalidBearerToken('Invalid bearer token signature.');
        }
        if ('JWT' !== ($header['typ'] ?? null) || 'HS256' !== ($header['alg'] ?? null)) {
            throw new InvalidBearerToken('Unsupported bearer token header.');
        }

        $subject = $claims['sub'] ?? null;
        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        if (!is_string($subject) || !$this->isUuid($subject) || !is_int($issuedAt) || !is_int($expiresAt)) {
            throw new InvalidBearerToken('Required bearer token claims are invalid.');
        }
        if ($this->issuer !== ($claims['iss'] ?? null) || !$this->hasAudience($claims['aud'] ?? null)) {
            throw new InvalidBearerToken('Bearer token issuer or audience is invalid.');
        }

        $timestamp = $now->getTimestamp();
        if ($issuedAt > $timestamp + $this->clockSkewSeconds) {
            throw new InvalidBearerToken('Bearer token is not active yet.');
        }
        if ($expiresAt <= $timestamp - $this->clockSkewSeconds || $expiresAt <= $issuedAt) {
            throw new InvalidBearerToken('Bearer token has expired.');
        }
        if ($expiresAt - $issuedAt > $this->maximumLifetimeSeconds) {
            throw new InvalidBearerToken('Bearer token lifetime exceeds the allowed maximum.');
        }
        $notBefore = $claims['nbf'] ?? null;
        if (null !== $notBefore && (!is_int($notBefore) || $notBefore > $timestamp + $this->clockSkewSeconds)) {
            throw new InvalidBearerToken('Bearer token is not active yet.');
        }

        return strtolower($subject);
    }

    /** @param array<string, mixed> $additionalClaims */
    public function issue(
        string $subject,
        \DateTimeImmutable $issuedAt,
        int $lifetimeSeconds,
        array $additionalClaims = [],
    ): string {
        if (!$this->isUuid($subject) || $lifetimeSeconds < 1 || $lifetimeSeconds > $this->maximumLifetimeSeconds) {
            throw new \InvalidArgumentException('Bearer token issuance parameters are invalid.');
        }

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $claims = [
            ...$additionalClaims,
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => strtolower($subject),
            'iat' => $issuedAt->getTimestamp(),
            'exp' => $issuedAt->getTimestamp() + $lifetimeSeconds,
        ];
        $encodedHeader = $this->encode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedClaims = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedHeader.'.'.$encodedClaims, $this->secret, true);

        return $encodedHeader.'.'.$encodedClaims.'.'.$this->encode($signature);
    }

    private function hasAudience(mixed $audience): bool
    {
        if (is_string($audience)) {
            return hash_equals($this->audience, $audience);
        }

        return is_array($audience)
            && in_array($this->audience, array_filter($audience, 'is_string'), true);
    }

    /** @return array<string, mixed> */
    private function json(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidBearerToken('Malformed bearer token JSON.', previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new InvalidBearerToken('Malformed bearer token JSON.');
        }

        return $decoded;
    }

    private function decode(string $value): string
    {
        if (1 === preg_match('/[^A-Za-z0-9_-]/', $value)) {
            throw new InvalidBearerToken('Malformed bearer token encoding.');
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
        if (false === $decoded) {
            throw new InvalidBearerToken('Malformed bearer token encoding.');
        }

        return $decoded;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value);
    }
}
