<?php

namespace App\Shared\Application\Idempotency;

use Symfony\Component\HttpFoundation\Request;

final class IdempotencyKeyResolver
{
    public function __construct(private readonly string $headerName = 'HTTP_IDEMPOTENCY_KEY')
    {
    }

    public function resolve(Request $request): ?string
    {
        $key = $request->headers->get('Idempotency-Key');
        if (null === $key) {
            $key = $request->server->get($this->headerName);
        }
        $key = trim((string) $key);

        return $key === '' ? null : $key;
    }
}
