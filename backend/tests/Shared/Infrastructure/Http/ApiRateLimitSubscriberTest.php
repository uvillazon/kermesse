<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Application\RateLimit\RateLimitDecision;
use App\Shared\Application\RateLimit\RateLimiter;
use App\Shared\Infrastructure\Http\ApiRateLimitSubscriber;
use App\Shared\Infrastructure\Http\AuthenticatedActorResolver;
use App\Shared\Infrastructure\RateLimit\RateLimitPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiRateLimitSubscriberTest extends TestCase
{
    public function testRejectsExceededAuthenticatedActionWithRetryHeaders(): void
    {
        $limiter = new class implements RateLimiter {
            /** @var array{string, string, int}|null */
            public ?array $consumed = null;

            public function consume(string $action, string $subject, int $limit, \DateTimeImmutable $now): RateLimitDecision
            {
                $this->consumed = [$action, $subject, $limit];

                return new RateLimitDecision(false, $limit, 0, 17);
            }
        };
        $subscriber = new ApiRateLimitSubscriber($limiter, new RateLimitPolicy(true, 5));
        $request = Request::create('/api/v1/organizations/o/events/e/sales', 'POST');
        $request->attributes->set('_route', 'api_v1_sales_create');
        $request->attributes->set(AuthenticatedActorResolver::ATTRIBUTE, 'user-1');

        try {
            $subscriber->limit($this->event($request));
            self::fail('Expected rate-limit rejection.');
        } catch (TooManyRequestsHttpException $exception) {
            self::assertSame(429, $exception->getStatusCode());
            self::assertSame(17, $exception->getHeaders()['Retry-After']);
            self::assertSame('5', $exception->getHeaders()['X-RateLimit-Limit']);
            self::assertSame('0', $exception->getHeaders()['X-RateLimit-Remaining']);
        }

        self::assertSame(['POST:api_v1_sales_create', 'user:user-1', 5], $limiter->consumed);
    }

    public function testDoesNotLimitHealth(): void
    {
        $limiter = new class implements RateLimiter {
            public bool $called = false;

            public function consume(string $action, string $subject, int $limit, \DateTimeImmutable $now): RateLimitDecision
            {
                $this->called = true;

                return new RateLimitDecision(true, $limit, $limit - 1, 0);
            }
        };
        $subscriber = new ApiRateLimitSubscriber($limiter, new RateLimitPolicy(true, 5));

        $subscriber->limit($this->event(Request::create('/api/v1/health', 'GET')));

        self::assertFalse($limiter->called);
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
