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

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Organizaciones a las que el usuario pertenece hoy. El frontend toma de aquí la organización
        // activa en vez de usar un id fijo. La vigencia sigue OrganizationMembership::isActiveAt().
        $nowSql = $now->format('Y-m-d H:i:s');
        $organizations = array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'access' => (string) $row['access_type'],
            ],
            $this->connection->fetchAllAssociative(
                'SELECT o.id, o.name, o.slug, om.access_type
                 FROM organization_memberships om
                 JOIN organizations o ON o.id = om.organization_id
                 WHERE om.user_id = ? AND om.status = ? AND o.status = ?
                   AND (om.valid_from IS NULL OR om.valid_from <= ?)
                   AND (om.valid_until IS NULL OR om.valid_until >= ?)
                 ORDER BY o.name, o.id',
                [$user['id'], 'active', 'active', $nowSql, $nowSql]
            )
        );
        // Compatibilidad: `orgAccess` es el acceso en la primera organización.
        $orgAccess = $organizations[0]['access'] ?? 'collaborator';

        // Consultar roles en eventos
        $roleRows = $this->connection->fetchAllAssociative(
            'SELECT r.code FROM roles r 
             JOIN event_membership_roles emr ON emr.role_id = r.id
             JOIN event_memberships em ON em.id = emr.event_membership_id
             WHERE em.user_id = ? AND em.status = ?',
            [$user['id'], 'active']
        );
        $roles = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['code'], $roleRows)));

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
                'organizations' => $organizations,
            ],
        ]);
    }
}
