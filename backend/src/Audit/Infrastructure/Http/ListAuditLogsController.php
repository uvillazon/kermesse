<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ApiRequestInput;
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

        $data = array_map(static function (array $r): array {
            $metadata = [];
            if (!empty($r['metadata_json'])) {
                $decoded = json_decode((string) $r['metadata_json'], true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }

            return [
                'id' => (string) $r['id'],
                'action' => (string) $r['action'],
                'aggregate_type' => (string) $r['aggregate_type'],
                'aggregate_id' => (string) ($r['aggregate_id'] ?? ''),
                'request_id' => (string) ($r['request_id'] ?? ''),
                'actor_name' => (string) ($r['actor_name'] ?? 'Sistema'),
                'actor_email' => (string) ($r['actor_email'] ?? ''),
                'metadata' => $metadata,
                'created_at' => (string) $r['created_at'],
            ];
        }, $rows);

        return new JsonResponse(['data' => $data]);
    }
}
