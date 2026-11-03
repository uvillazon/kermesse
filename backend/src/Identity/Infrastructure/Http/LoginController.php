<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\Security\HmacBearerTokenCodec;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LoginController
{
    public function __construct(
        private HmacBearerTokenCodec $tokens,
        private Connection $connection,
        private ApiRequestInput $input,
    ) {
    }

    #[Route('/api/v1/auth/login', name: 'api_v1_auth_login', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $this->input->object($request, ['email', 'password']);
        $email = $this->input->requiredString($payload, 'email');
        $password = $this->input->requiredString($payload, 'password');

        $norm = strtolower(trim($email));
        $user = $this->connection->fetchAssociative(
            'SELECT id, email, display_name, status FROM users WHERE email_normalized = ?',
            [$norm]
        );

        if (false === $user || 'active' !== $user['status']) {
            throw new UnauthorizedHttpException('Bearer', 'Credenciales inválidas o cuenta inactiva.');
        }

        // Validación de contraseña para el prototipo / Hito 0 (acepta password123 o cualquier clave >= 6 chars)
        if ($password !== 'password123' && strlen($password) < 6) {
            throw new UnauthorizedHttpException('Bearer', 'Contraseña incorrecta.');
        }

        // Consultar membresía de organización
        $orgMembership = $this->connection->fetchAssociative(
            'SELECT access_type FROM organization_memberships WHERE user_id = ? AND status = ? LIMIT 1',
            [$user['id'], 'active']
        );
        $orgAccess = false !== $orgMembership ? (string) $orgMembership['access_type'] : 'collaborator';

        // Consultar roles en eventos
        $roleRows = $this->connection->fetchAllAssociative(
            'SELECT r.code FROM roles r 
             JOIN event_membership_roles emr ON emr.role_id = r.id
             JOIN event_memberships em ON em.id = emr.event_membership_id
             WHERE em.user_id = ? AND em.status = ?',
            [$user['id'], 'active']
        );
        $roles = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['code'], $roleRows)));

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $token = $this->tokens->issue(
            $user['id'],
            $now,
            3600, // 1 hora de vigencia
            [
                'email' => $user['email'],
                'display_name' => $user['display_name'],
                'roles' => $roles,
                'orgAccess' => $orgAccess,
            ]
        );

        return new JsonResponse([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['display_name'],
                'orgAccess' => $orgAccess,
                'roles' => $roles,
            ],
        ]);
    }
}
