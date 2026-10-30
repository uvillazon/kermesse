<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Identity\Domain\EventRole;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ApiRequestInput
{
    /**
     * @param list<string> $allowedFields
     * @return array<string, mixed>
     */
    public function object(Request $request, array $allowedFields): array
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }
        if (array_is_list($payload)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }
        if ([] !== array_diff(array_keys($payload), $allowedFields)) {
            throw new UnprocessableEntityHttpException('Request body contains unknown fields.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new UnprocessableEntityHttpException($field.' is required.');
        }

        return trim($value);
    }

    public function uuid(string $value, string $field): string
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new UnprocessableEntityHttpException($field.' must be a UUID.');
        }

        return $value;
    }

    /** @return list<EventRole> */
    public function roles(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || [] === $value) {
            throw new UnprocessableEntityHttpException('roles must be a non-empty list.');
        }

        $roles = [];
        foreach ($value as $code) {
            if (!is_string($code) || null === ($role = EventRole::tryFrom($code))) {
                throw new UnprocessableEntityHttpException('roles contains an invalid event role.');
            }
            $roles[] = $role;
        }

        return $roles;
    }

    /** @param array<string, mixed> $payload */
    public function optionalDate(array $payload, string $field): ?\DateTimeImmutable
    {
        $value = $payload[$field] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === trim($value)) {
            throw new UnprocessableEntityHttpException($field.' must be an ISO 8601 date-time.');
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new UnprocessableEntityHttpException($field.' must be an ISO 8601 date-time.');
        }
    }
}
