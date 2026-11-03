<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class CorsSubscriber
{
    private const ALLOWED_ORIGINS = [
        'http://localhost:4200',
        'http://127.0.0.1:4200',
    ];

    private const ALLOWED_HEADERS = [
        'Authorization',
        'Content-Type',
        'Accept',
        'Origin',
        'X-Requested-With',
        'Idempotency-Key',
    ];

    private const ALLOWED_METHODS = [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ];

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 100)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('OPTIONS' === $request->getMethod()) {
            $origin = $request->headers->get('Origin');
            $allowedOrigin = $this->resolveOrigin($origin);

            $response = new Response('', Response::HTTP_NO_CONTENT, [
                'Access-Control-Allow-Origin' => $allowedOrigin,
                'Access-Control-Allow-Methods' => implode(', ', self::ALLOWED_METHODS),
                'Access-Control-Allow-Headers' => implode(', ', self::ALLOWED_HEADERS),
                'Access-Control-Max-Age' => '3600',
                'Vary' => 'Origin',
            ]);

            $event->setResponse($response);
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 0)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $origin = $request->headers->get('Origin');
        $allowedOrigin = $this->resolveOrigin($origin);

        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', self::ALLOWED_METHODS));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', self::ALLOWED_HEADERS));
        $response->headers->set('Vary', 'Origin');
    }

    private function resolveOrigin(?string $origin): string
    {
        if (null !== $origin && in_array($origin, self::ALLOWED_ORIGINS, true)) {
            return $origin;
        }

        return '*';
    }
}
