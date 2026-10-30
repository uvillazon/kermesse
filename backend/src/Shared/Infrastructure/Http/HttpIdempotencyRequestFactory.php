<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Idempotency\CanonicalRequestHasher;
use App\Shared\Application\Idempotency\IdempotencyKeyResolver;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class HttpIdempotencyRequestFactory
{
    public function __construct(
        private IdempotencyKeyResolver $keys,
        private CanonicalRequestHasher $hasher,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function create(
        Request $request,
        array $payload,
        string $recordId,
        string $organizationId,
        ?string $eventId,
        string $actorUserId,
        \DateTimeImmutable $createdAt,
    ): IdempotencyRequest {
        $key = $this->keys->resolve($request);
        if (null === $key) {
            throw new BadRequestHttpException('Idempotency-Key header is required.');
        }
        if (strlen($key) > 128) {
            throw new BadRequestHttpException('Idempotency-Key header cannot exceed 128 characters.');
        }

        return new IdempotencyRequest(
            $recordId,
            $organizationId,
            $eventId,
            $actorUserId,
            $key,
            $request->getMethod(),
            $request->getPathInfo(),
            $this->hasher->hash($payload),
            $createdAt,
            $createdAt->modify('+24 hours'),
        );
    }
}
