<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Domain\Permission;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\Http\AuthenticatedActorResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListEventMembersController
{
    public function __construct(
        private Connection $connection,
        private ApiRequestInput $input,
        private AuthorizationPolicy $policy,
        private AuthenticatedActorResolver $actors,
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

        $decision = $this->policy->decide(new AuthorizationRequest(
            $this->actors->resolve($request),
            $organizationId,
            Permission::ORGANIZATION_MANAGE_MEMBERS,
            $eventId,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
        if (!$decision->allowed) {
            throw new AccessDeniedHttpException('Solo propietarios o administradores organizacionales pueden consultar los miembros.');
        }

        $eventBelongsToOrganization = false !== $this->connection->fetchOne(
            'SELECT 1 FROM events WHERE id = ? AND organization_id = ?',
            [$eventId, $organizationId],
        );
        if (!$eventBelongsToOrganization) {
            throw new NotFoundHttpException('El evento no existe en esta organización.');
        }

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
