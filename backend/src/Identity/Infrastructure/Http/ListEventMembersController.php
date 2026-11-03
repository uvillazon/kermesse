<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ApiRequestInput;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListEventMembersController
{
    public function __construct(
        private Connection $connection,
        private ApiRequestInput $input,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/members',
        name: 'api_v1_identity_list_members',
        methods: ['GET']
    )]
    public function __invoke(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id, u.display_name, u.email, om.access_type as org_access, u.status,
                    GROUP_CONCAT(r.code SEPARATOR ",") as roles
             FROM users u
             JOIN organization_memberships om ON om.user_id = u.id AND om.organization_id = ?
             LEFT JOIN event_memberships em ON em.user_id = u.id AND em.event_id = ?
             LEFT JOIN event_membership_roles emr ON emr.event_membership_id = em.id
             LEFT JOIN roles r ON r.id = emr.role_id
             GROUP BY u.id, u.display_name, u.email, om.access_type, u.status
             ORDER BY u.display_name ASC',
            [$organizationId, $eventId]
        );

        $members = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['display_name'],
            'email' => (string) $row['email'],
            'orgAccess' => (string) $row['org_access'],
            'status' => (string) $row['status'],
            'roles' => !empty($row['roles']) ? explode(',', (string) $row['roles']) : [],
        ], $rows);

        return new JsonResponse(['data' => $members]);
    }
}
