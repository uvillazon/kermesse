<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class QueryPagination
{
    public function resolve(Request $request): PageRequest
    {
        return new PageRequest(
            $this->positiveInteger($request, 'page', 1, null),
            $this->positiveInteger($request, 'page_size', 25, 100),
        );
    }

    private function positiveInteger(Request $request, string $name, int $default, ?int $maximum): int
    {
        $raw = $request->query->get($name);
        if (null === $raw || '' === $raw) {
            return $default;
        }
        if (!is_string($raw) || 1 !== preg_match('/^[1-9]\d*$/', $raw)) {
            throw new UnprocessableEntityHttpException($name.' must be a positive integer.');
        }

        $value = (int) $raw;
        if (null !== $maximum && $value > $maximum) {
            throw new UnprocessableEntityHttpException($name.' must not be greater than '.$maximum.'.');
        }

        return $value;
    }
}
