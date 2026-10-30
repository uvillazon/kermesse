<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\Invitation\InvitationDeliveryOutbox;
use Doctrine\DBAL\Connection;

final readonly class DoctrineInvitationDeliveryOutbox implements InvitationDeliveryOutbox
{
    public function __construct(private Connection $connection)
    {
    }

    public function enqueue(
        string $deliveryId,
        string $invitationId,
        string $organizationId,
        ?string $eventId,
        string $recipient,
        string $tokenKeyVersion,
        \DateTimeImmutable $at,
    ): void {
        $timestamp = $at->format('Y-m-d H:i:s');
        $this->connection->insert('notification_deliveries', [
            'id' => $deliveryId,
            'organization_id' => $organizationId,
            'event_id' => $eventId,
            'aggregate_type' => 'access_invitation',
            'aggregate_id' => $invitationId,
            'channel' => 'email',
            'recipient' => $recipient,
            'template' => 'identity.access_invitation',
            'payload_json' => json_encode(['invitation_id' => $invitationId], JSON_THROW_ON_ERROR),
            'token_key_version' => $tokenKeyVersion,
            'status' => 'pending',
            'attempts' => 0,
            'provider_reference' => null,
            'next_attempt_at' => $timestamp,
            'last_error' => null,
            'created_at' => $timestamp,
            'sent_at' => null,
        ]);
    }
}
