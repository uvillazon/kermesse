<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\AcceptInvitationCommand;
use App\Identity\Application\Command\AcceptInvitationHandler;
use App\Shared\Application\Identifier\IdentifierGenerator;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AcceptInvitationController
{
    public function __construct(
        private AcceptInvitationHandler $handler,
        private ApiRequestInput $input,
        private IdentifierGenerator $identifiers,
    ) {
    }

    #[Route(
        '/api/v1/invitations/accept',
        name: 'api_v1_identity_accept_invitation',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $this->input->object($request, ['token', 'email', 'display_name']);
        $token = $this->input->requiredString($payload, 'token');
        $email = strtolower($this->input->requiredString($payload, 'email'));
        $displayName = $this->input->requiredString($payload, 'display_name');

        if (43 !== strlen($token) || 1 !== preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            throw new UnprocessableEntityHttpException('Invitation is invalid, expired or already consumed.');
        }
        if (strlen($email) > 254 || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UnprocessableEntityHttpException('email is invalid.');
        }
        if (strlen($displayName) > 160) {
            throw new UnprocessableEntityHttpException('display_name cannot exceed 160 characters.');
        }

        $userId = $this->handler->handle(new AcceptInvitationCommand(
            $token,
            $email,
            $displayName,
            $this->identifiers->generate(),
            $this->identifiers->generate(),
            $this->identifiers->generate(),
            $this->identifiers->generate(),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        return new JsonResponse(['data' => [
            'user_id' => $userId,
            'status' => 'accepted',
        ]]);
    }
}
