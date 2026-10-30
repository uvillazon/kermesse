<?php

declare(strict_types=1);

namespace App\Identity\Application\Invitation;

interface InvitationTokenGenerator
{
    public function generate(string $invitationId, ?string $keyVersion = null): string;

    public function keyVersion(): string;
}
