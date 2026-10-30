<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class AuthenticatedActorResolver
{
    public const ATTRIBUTE = 'authenticated_user_id';

    public function resolve(Request $request): string
    {
        $userId = $request->attributes->get(self::ATTRIBUTE);
        if (!is_string($userId) || '' === trim($userId)) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication is required.');
        }

        return $userId;
    }
}
