<?php

declare(strict_types=1);

namespace App\Event\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ApiRequestInput;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListOrganizationEventsController
{
    public function __construct(
        private Connection $connection,
        private ApiRequestInput $input,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events',
        name: 'api_v1_events_list',
        methods: ['GET']
    )]
    public function __invoke(Request $request, string $organizationId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id, e.slug, e.name, e.status, e.starts_at, e.created_at,
                    (SELECT COUNT(*) FROM stands s WHERE s.event_id = e.id) as stands_count
             FROM events e
             WHERE e.organization_id = ?
             ORDER BY e.created_at DESC',
            [$organizationId]
        );

        $events = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'date' => null !== $row['starts_at'] ? (new \DateTimeImmutable((string) $row['starts_at']))->format('d M Y') : '28 oct 2026',
            'stands' => (int) $row['stands_count'],
        ], $rows);

        return new JsonResponse(['data' => $events]);
    }
}
