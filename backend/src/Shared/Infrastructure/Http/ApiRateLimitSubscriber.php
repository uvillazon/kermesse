<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\RateLimit\RateLimiter;
use App\Shared\Infrastructure\RateLimit\RateLimitPolicy;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiRateLimitSubscriber
{
    public function __construct(
        private RateLimiter $limiter,
        private RateLimitPolicy $policy,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function limit(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->policy->isEnabled()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/v1/') || '/api/v1/health' === $path) {
            return;
        }

        $actor = $request->attributes->get(AuthenticatedActorResolver::ATTRIBUTE);
        $subject = is_string($actor) && '' !== $actor
            ? 'user:'.$actor
            : 'ip:'.($request->getClientIp() ?? 'unknown');
        $route = $request->attributes->get('_route');
        $action = $request->getMethod().':'.(is_string($route) ? $route : $path);
        $decision = $this->limiter->consume(
            $action,
            $subject,
            $this->policy->defaultPerMinute(),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        if (!$decision->allowed) {
            throw new TooManyRequestsHttpException(
                $decision->retryAfterSeconds,
                'Se excedió el límite de solicitudes. Intente nuevamente más tarde.',
                null,
                0,
                [
                    'X-RateLimit-Limit' => (string) $decision->limit,
                    'X-RateLimit-Remaining' => '0',
                ],
            );
        }
    }
}
