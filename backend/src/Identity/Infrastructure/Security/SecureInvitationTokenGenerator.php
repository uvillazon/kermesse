<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\Invitation\InvitationTokenGenerator;

final class SecureInvitationTokenGenerator implements InvitationTokenGenerator
{
    public function __construct(
        private readonly string $secret,
        private readonly string $version,
        private readonly array $legacySecrets = [],
    ) {
        if (strlen($this->secret) < 32) {
            throw new \InvalidArgumentException('Invitation token secret must contain at least 32 characters.');
        }
        if (!$this->isValidVersion($this->version)) {
            throw new \InvalidArgumentException('Invitation token key version is invalid.');
        }
        foreach ($this->legacySecrets as $legacyVersion => $legacySecret) {
            if (
                !is_string($legacyVersion)
                || !$this->isValidVersion($legacyVersion)
                || !is_string($legacySecret)
                || strlen($legacySecret) < 32
            ) {
                throw new \InvalidArgumentException('Invitation legacy token keyring is invalid.');
            }
            if ($legacyVersion === $this->version) {
                throw new \InvalidArgumentException('Active invitation key version cannot also be legacy.');
            }
        }
    }

    public function generate(string $invitationId, ?string $keyVersion = null): string
    {
        $keyVersion ??= $this->version;
        $secret = $keyVersion === $this->version
            ? $this->secret
            : ($this->legacySecrets[$keyVersion] ?? null);
        if (!is_string($secret)) {
            throw new \RuntimeException('Invitation token key version is not available: '.$keyVersion);
        }
        $bytes = hash_hmac(
            'sha256',
            'kermesfera:invitation:'.$keyVersion.':'.$invitationId,
            $secret,
            true,
        );

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public function keyVersion(): string
    {
        return $this->version;
    }

    private function isValidVersion(string $version): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $version);
    }
}
