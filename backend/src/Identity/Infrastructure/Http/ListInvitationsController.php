<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ApiRequestInput;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListInvitationsController
{
    public function __construct(
        private Connection $connection,
        private ApiRequestInput $input,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/invitations',
        name: 'api_v1_identity_list_invitations',
        methods: ['GET']
    )]
    public function __invoke(Request $request, string $organizationId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ai.id, ai.email_normalized, ai.target_access_type, ai.status,
                    ai.expires_at, ai.created_at, ai.roles_json,
                    u.display_name AS invited_by_name
             FROM access_invitations ai
             LEFT JOIN users u ON u.id = ai.invited_by
             WHERE ai.organization_id = ?
             ORDER BY ai.created_at DESC',
            [$organizationId]
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $data = array_map(static function (array $r) use ($now): array {
            $roles = [];
            if (!empty($r['roles_json'])) {
                $decoded = json_decode((string) $r['roles_json'], true);
                if (is_array($decoded)) {
                    $roles = array_values(array_map('strval', $decoded));
                }
            }

            $expiresAt = new \DateTimeImmutable((string) $r['expires_at'], new \DateTimeZone('UTC'));
            $isExpired = $expiresAt <= $now && 'pending' === (string) $r['status'];
            $status = $isExpired ? 'expired' : (string) $r['status'];

            return [
                'id' => (string) $r['id'],
                'email' => (string) $r['email_normalized'],
                'roles' => $roles,
                'status' => $status,
                // `expires_at` en ISO 8601 (UTC) para que el cliente lo muestre en su idioma y zona horaria.
                'expires_at' => $expiresAt->format(\DATE_ATOM),
                'expires' => $expiresAt->format('d M Y H:i'),
                'invited_by_name' => (string) ($r['invited_by_name'] ?? 'Administrador'),
                'created_at' => (string) $r['created_at'],
            ];
        }, $rows);

        return new JsonResponse(['data' => $data]);
    }
}
