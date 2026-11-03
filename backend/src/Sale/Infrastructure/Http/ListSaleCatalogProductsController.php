<?php

declare(strict_types=1);

namespace App\Sale\Infrastructure\Http;

use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Domain\Permission;
use App\Sale\Application\ExpireReservations\ExpireSaleReservationsHandler;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\Http\AuthenticatedActorResolver;
use App\Shared\Infrastructure\Http\QueryPagination;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListSaleCatalogProductsController
{
    public function __construct(
        private Connection $connection,
        private ApiRequestInput $input,
        private AuthorizationPolicy $policy,
        private AuthenticatedActorResolver $actors,
        private QueryPagination $pagination,
        private ExpireSaleReservationsHandler $expireReservations,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/catalog/products',
        name: 'api_v1_catalog_products_list',
        methods: ['GET']
    )]
    public function __invoke(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $actorUserId = $this->actors->resolve($request);
        $decision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::CATALOG_READ,
            $eventId,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
        if (!$decision->allowed) {
            throw new AccessDeniedHttpException('No tienes permiso para consultar el catálogo de este evento.');
        }
        $this->expireReservations->handle($eventId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $page = $this->pagination->resolve($request);
        $search = trim((string) $request->query->get('search', ''));
        if (strlen($search) > 100) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException(
                'search must not be longer than 100 characters.',
            );
        }
        $status = $request->query->get('status');
        if (null !== $status && !in_array($status, ['active', 'paused'], true)) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException(
                'status must be active or paused.',
            );
        }

        $conditions = ['p.event_id = ?', 'e.organization_id = ?'];
        $parameters = [$eventId, $organizationId];
        if ('' !== $search) {
            $conditions[] = '(p.code LIKE ? OR p.name LIKE ? OR p.description LIKE ? OR s.name LIKE ?)';
            $term = '%'.$search.'%';
            array_push($parameters, $term, $term, $term, $term);
        }
        if (null !== $status) {
            $conditions[] = 'p.active = ?';
            $parameters[] = 'active' === $status ? 1 : 0;
        }
        $where = implode(' AND ', $conditions);
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM products p
             JOIN stands s ON s.id = p.stand_id
             JOIN events e ON e.id = p.event_id
             WHERE '.$where,
            $parameters,
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.stand_id, p.code, p.name, p.description, p.unit_price_minor, 
                    p.currency, p.stock, p.sold, p.active, p.version, s.name as stand_name
             FROM products p
             JOIN stands s ON s.id = p.stand_id
             JOIN events e ON e.id = p.event_id
             WHERE '.$where.'
             ORDER BY s.name ASC, p.name ASC
             LIMIT '.$page->pageSize.' OFFSET '.$page->offset(),
            $parameters,
        );

        $data = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'stand_id' => (string) $row['stand_id'],
            'stand_name' => (string) $row['stand_name'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'description' => $row['description'],
            'unit_price' => number_format((int) $row['unit_price_minor'] / 100, 2, '.', ''),
            'unit_price_minor' => (int) $row['unit_price_minor'],
            'currency' => (string) $row['currency'],
            'stock' => (int) $row['stock'],
            'sold' => (int) $row['sold'],
            'available_quantity' => max(0, (int) $row['stock'] - (int) $row['sold']),
            'status' => ((bool) $row['active']) ? 'active' : 'paused',
            'active' => (bool) $row['active'],
            'version' => (int) $row['version'],
        ], $rows);

        return new JsonResponse([
            'data' => $data,
            'meta' => $page->metadata($total),
        ]);
    }
}
