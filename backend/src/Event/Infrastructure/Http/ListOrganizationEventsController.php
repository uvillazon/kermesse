<?php

declare(strict_types=1);

namespace App\Event\Infrastructure\Http;

use App\Identity\Application\Access\AccessDenialReason;
use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Domain\Permission;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\Http\AuthenticatedActorResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Propietario y administrador (org.read) ven todos los eventos de la organización.
 * Un colaborador activo ve solo los eventos donde la política le concede event.read.
 * Sin membresía vigente en la organización la respuesta es 403.
 */
final readonly class ListOrganizationEventsController
{
    private const SELECT_EVENTS = 'SELECT e.id, e.slug, e.name, e.status, e.starts_at, e.created_at,
                (SELECT COUNT(*) FROM stands s WHERE s.event_id = e.id) as stands_count
         FROM events e';

    public function __construct(
        private Connection $connection,
        private ApiRequestInput $input,
        private AuthorizationPolicy $policy,
        private AuthenticatedActorResolver $actors,
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
        $actorUserId = $this->actors->resolve($request);
        $at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $organizationDecision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::ORGANIZATION_READ,
            null,
            $at,
        ));

        if ($organizationDecision->allowed) {
            $rows = $this->connection->fetchAllAssociative(
                self::SELECT_EVENTS.' WHERE e.organization_id = ? ORDER BY e.created_at DESC',
                [$organizationId],
            );
        } elseif (AccessDenialReason::ORGANIZATION_MANAGEMENT_NOT_ALLOWED === $organizationDecision->reason) {
            $rows = $this->eventsVisibleToCollaborator($actorUserId, $organizationId, $at);
        } else {
            throw new AccessDeniedHttpException('No tienes acceso a los eventos de esta organización.');
        }

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

    /**
     * La consulta solo preselecciona eventos con asignación activa; la política decide
     * vigencia, roles y acceso histórico de cada uno.
     *
     * @return list<array<string, mixed>>
     */
    private function eventsVisibleToCollaborator(string $actorUserId, string $organizationId, \DateTimeImmutable $at): array
    {
        $candidates = $this->connection->fetchAllAssociative(
            self::SELECT_EVENTS.'
             INNER JOIN event_memberships em ON em.event_id = e.id AND em.user_id = ? AND em.status = ?
             WHERE e.organization_id = ?
             ORDER BY e.created_at DESC',
            [$actorUserId, 'active', $organizationId],
        );

        return array_values(array_filter(
            $candidates,
            fn (array $row): bool => $this->policy->decide(new AuthorizationRequest(
                $actorUserId,
                $organizationId,
                Permission::EVENT_READ,
                (string) $row['id'],
                $at,
            ))->allowed,
        ));
    }
}
