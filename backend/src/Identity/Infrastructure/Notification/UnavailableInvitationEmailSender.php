<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Notification;

use App\Identity\Application\Invitation\InvitationEmailSender;

final class UnavailableInvitationEmailSender implements InvitationEmailSender
{
    public function send(string $recipient, string $invitationId, string $token): string
    {
        throw new \RuntimeException('Invitation email provider is not configured.');
    }
}
