<?php

declare(strict_types=1);

namespace App\Identity\Application\Invitation;

use App\Shared\Application\Identifier\IdentifierGenerator;

final readonly class InvitationDeliveryProcessor
{
    public function __construct(
        private InvitationDeliveryQueue $queue,
        private InvitationEmailSender $sender,
        private InvitationTokenGenerator $tokens,
        private IdentifierGenerator $identifiers,
    ) {
    }

    public function processOne(\DateTimeImmutable $at): bool
    {
        $lockToken = $this->identifiers->generate();
        $delivery = $this->queue->claim($lockToken, $at, $at->modify('-5 minutes'));
        if (null === $delivery) {
            return false;
        }

        try {
            $token = $this->tokens->generate($delivery->invitationId, $delivery->tokenKeyVersion);
            $providerReference = $this->sender->send(
                $delivery->recipient,
                $delivery->invitationId,
                $token,
            );
            $this->queue->markSent($delivery->deliveryId, $delivery->lockToken, $providerReference, $at);
        } catch (\Throwable $exception) {
            $delayMinutes = min(60, 2 ** min(5, $delivery->attempt));
            $this->queue->releaseForRetry(
                $delivery->deliveryId,
                $delivery->lockToken,
                substr($exception->getMessage(), 0, 2000),
                $at->modify('+'.$delayMinutes.' minutes'),
            );
        }

        return true;
    }
}
