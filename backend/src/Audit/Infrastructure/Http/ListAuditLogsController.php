<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ApiRequestInput;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListAuditLogsController
{
    public function __construct(
        private Connection $connection,
        private ApiRequestInput $input,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/audit-logs',
        name: 'api_v1_audit_logs_list',
        methods: ['GET']
    )]
    public function __invoke(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.action, a.aggregate_type, a.aggregate_id, a.request_id,
                    a.metadata_json, a.created_at, u.display_name AS actor_name, u.email AS actor_email
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.organization_id = ? AND (a.event_id = ? OR a.event_id IS NULL)
             ORDER BY a.created_at DESC
             LIMIT 100',
            [$organizationId, $eventId]
        );

        $metadataById = [];
        foreach ($rows as $r) {
            $decoded = empty($r['metadata_json']) ? null : json_decode((string) $r['metadata_json'], true);
            $metadataById[(string) $r['id']] = is_array($decoded) ? $decoded : [];
        }
        $subjectNames = $this->subjectNames($metadataById);

        $data = array_map(static function (array $r) use ($metadataById, $subjectNames): array {
            $metadata = $metadataById[(string) $r['id']];
            $subjectId = self::subjectUserId($metadata);

            return [
                'id' => (string) $r['id'],
                'action' => (string) $r['action'],
                'aggregate_type' => (string) $r['aggregate_type'],
                'aggregate_id' => (string) ($r['aggregate_id'] ?? ''),
                'request_id' => (string) ($r['request_id'] ?? ''),
                'actor_name' => (string) ($r['actor_name'] ?? 'Sistema'),
                'actor_email' => (string) ($r['actor_email'] ?? ''),
                // Nombre de la persona afectada (asignaciones, revocaciones, transferencias), para que el
                // historial pueda decir "a Luis Méndez" en vez de mostrar su UUID. Null si no aplica.
                'subject_name' => null !== $subjectId ? ($subjectNames[$subjectId] ?? null) : null,
                'metadata' => $metadata,
                'created_at' => (string) $r['created_at'],
            ];
        }, $rows);

        return new JsonResponse(['data' => $data]);
    }

    /** @param array<string, mixed> $metadata */
    private static function subjectUserId(array $metadata): ?string
    {
        foreach (['target_user_id', 'new_owner_user_id'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key])) {
                return $metadata[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $metadataById
     *
     * @return array<string, string> nombre visible por id de usuario
     */
    private function subjectNames(array $metadataById): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $metadata): ?string => self::subjectUserId($metadata),
            $metadataById,
        ))));
        if ([] === $ids) {
            return [];
        }

        $names = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, display_name FROM users WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::STRING],
        ) as $user) {
            $names[(string) $user['id']] = (string) $user['display_name'];
        }

        return $names;
    }
}
