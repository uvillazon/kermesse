<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\Invitation\InvitationDeliveryQueue;
use App\Identity\Application\Invitation\PendingInvitationDelivery;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;
use Doctrine\DBAL\Connection;

final readonly class DoctrineInvitationDeliveryQueue implements InvitationDeliveryQueue
{
    public function __construct(
        private Connection $connection,
        private TransactionalExecutor $transactions,
    ) {
    }

    public function claim(string $lockToken, \DateTimeImmutable $at, \DateTimeImmutable $staleBefore): ?PendingInvitationDelivery
    {
        return $this->transactions->execute(function () use ($lockToken, $at, $staleBefore): ?PendingInvitationDelivery {
            $row = $this->connection->fetchAssociative(
                "SELECT id, aggregate_id, recipient, token_key_version, attempts
                 FROM notification_deliveries
                 WHERE template = 'identity.access_invitation'
                   AND ((status = 'pending' AND next_attempt_at <= ?)
                     OR (status = 'processing' AND locked_at <= ?))
                 ORDER BY created_at, id
                 LIMIT 1 FOR UPDATE",
                [$at->format('Y-m-d H:i:s'), $staleBefore->format('Y-m-d H:i:s')],
            );
            if (false === $row) {
                return null;
            }

            $attempt = (int) $row['attempts'] + 1;
            $this->connection->update('notification_deliveries', [
                'status' => 'processing',
                'attempts' => $attempt,
                'lock_token' => $lockToken,
                'locked_at' => $at->format('Y-m-d H:i:s'),
            ], ['id' => $row['id']]);

            return new PendingInvitationDelivery(
                (string) $row['id'],
                (string) $row['aggregate_id'],
                (string) $row['recipient'],
                (string) $row['token_key_version'],
                $lockToken,
                $attempt,
            );
        });
    }

    public function markSent(string $deliveryId, string $lockToken, string $providerReference, \DateTimeImmutable $at): void
    {
        $this->transactions->execute(function () use ($deliveryId, $lockToken, $providerReference, $at): void {
            $changed = $this->connection->update('notification_deliveries', [
                'status' => 'sent',
                'provider_reference' => substr($providerReference, 0, 160),
                'sent_at' => $at->format('Y-m-d H:i:s'),
                'last_error' => null,
                'lock_token' => null,
                'locked_at' => null,
            ], ['id' => $deliveryId, 'status' => 'processing', 'lock_token' => $lockToken]);
            if (1 !== $changed) {
                throw new \RuntimeException('Invitation delivery lease was lost before completion.');
            }
        });
    }

    public function releaseForRetry(
        string $deliveryId,
        string $lockToken,
        string $error,
        \DateTimeImmutable $nextAttemptAt,
    ): void {
        $this->transactions->execute(function () use ($deliveryId, $lockToken, $error, $nextAttemptAt): void {
            $changed = $this->connection->update('notification_deliveries', [
                'status' => 'pending',
                'next_attempt_at' => $nextAttemptAt->format('Y-m-d H:i:s'),
                'last_error' => $error,
                'lock_token' => null,
                'locked_at' => null,
            ], ['id' => $deliveryId, 'status' => 'processing', 'lock_token' => $lockToken]);
            if (1 !== $changed) {
                throw new \RuntimeException('Invitation delivery lease was lost before retry scheduling.');
            }
        });
    }
}
