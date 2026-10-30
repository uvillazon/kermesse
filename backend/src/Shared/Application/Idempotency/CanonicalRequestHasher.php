<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

final class CanonicalRequestHasher
{
    /** @param array<string, mixed> $payload */
    public function hash(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->normalize($payload),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}
