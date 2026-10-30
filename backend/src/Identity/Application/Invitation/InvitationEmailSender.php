<?php

declare(strict_types=1);

namespace App\Identity\Application\Invitation;

interface InvitationEmailSender
{
    public function send(string $recipient, string $invitationId, string $token): string;
}
