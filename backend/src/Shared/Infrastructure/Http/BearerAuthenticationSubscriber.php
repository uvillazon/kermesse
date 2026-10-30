<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Security\HmacBearerTokenCodec;
use App\Shared\Infrastructure\Security\InvalidBearerToken;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class BearerAuthenticationSubscriber
{
    public function __construct(private HmacBearerTokenCodec $tokens)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 50)]
    public function authenticate(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/v1/') || in_array($path, [
            '/api/v1/health',
            '/api/v1/invitations/accept',
        ], true)) {
            return;
        }
        if ($request->attributes->has(AuthenticatedActorResolver::ATTRIBUTE)) {
            return;
        }

        $header = $request->headers->get('Authorization');
        if (!is_string($header) || 1 !== preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/D', $header, $matches)) {
            throw new UnauthorizedHttpException('Bearer', 'A valid bearer token is required.');
        }

        try {
            $subject = $this->tokens->subject($matches[1], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (InvalidBearerToken) {
            throw new UnauthorizedHttpException('Bearer', 'A valid bearer token is required.');
        }

        $request->attributes->set(AuthenticatedActorResolver::ATTRIBUTE, $subject);
    }
}
