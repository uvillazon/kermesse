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
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListSalesController
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
        '/api/v1/organizations/{organizationId}/events/{eventId}/sales',
        name: 'api_v1_sales_list',
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
            Permission::SALE_WRITE,
            $eventId,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
        if (!$decision->allowed) {
            throw new AccessDeniedHttpException('No tienes permiso para consultar las ventas de este evento.');
        }
        $this->expireReservations->handle($eventId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $page = $this->pagination->resolve($request);
        $conditions = ['s.organization_id = ?', 's.event_id = ?'];
        $parameters = [$organizationId, $eventId];
        $this->addEnumFilter(
            $request,
            'status',
            ['pending_payment', 'confirmed', 'cancelled', 'refunded'],
            's.status',
            $conditions,
            $parameters,
        );
        $this->addEnumFilter(
            $request,
            'payment_status',
            ['pending', 'to_verify', 'confirmed', 'rejected', 'cancelled', 'refunded'],
            'p.status',
            $conditions,
            $parameters,
        );
        $buyerEmail = trim((string) $request->query->get('buyer_email', ''));
        if ('' !== $buyerEmail) {
            if (strlen($buyerEmail) > 254 || false === filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
                throw new UnprocessableEntityHttpException('buyer_email must be a valid email address.');
            }
            $conditions[] = 's.buyer_email = ?';
            $parameters[] = strtolower($buyerEmail);
        }
        $this->addDateFilter($request, 'created_from', 's.created_at >= ?', $conditions, $parameters);
        $this->addDateFilter($request, 'created_until', 's.created_at <= ?', $conditions, $parameters);
        $where = implode(' AND ', $conditions);
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT s.id)
             FROM sales s
             LEFT JOIN payments p ON p.sale_id = s.id
             WHERE '.$where,
            $parameters,
        );

        $sales = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.organization_id, s.event_id, s.operator_user_id, s.channel, s.status,
                    s.buyer_email, s.buyer_name, s.buyer_phone, s.subtotal_minor, s.discount_total_minor,
                    s.total_minor, s.currency, s.confirmed_at, s.reservation_expires_at, s.created_at,
                    u.display_name as operator_name,
                    p.id as payment_id, p.method as payment_method, p.status as payment_status,
                    p.amount_minor as payment_amount_minor, p.confirmed_at as payment_confirmed_at,
                    t.id as ticket_id, t.ticket_number, t.status as ticket_status, t.qr_code
             FROM sales s
             LEFT JOIN users u ON u.id = s.operator_user_id
             LEFT JOIN payments p ON p.sale_id = s.id
             LEFT JOIN tickets t ON t.sale_id = s.id
             WHERE '.$where.'
             ORDER BY s.created_at DESC
             LIMIT '.$page->pageSize.' OFFSET '.$page->offset(),
            $parameters,
        );

        $saleIds = array_column($sales, 'id');
        $itemsBySale = [];

        if ([] !== $saleIds) {
            $items = $this->connection->fetchAllAssociative(
                'SELECT si.id, si.sale_id, si.product_id, si.product_code_snapshot, si.product_name_snapshot,
                        si.unit_price_minor_snapshot, si.quantity, si.line_total_minor, si.version_snapshot
                 FROM sale_items si
                 WHERE si.sale_id IN (?)
                 ORDER BY si.created_at ASC',
                [$saleIds],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );

            foreach ($items as $item) {
                $itemsBySale[$item['sale_id']][] = [
                    'id' => (string) $item['id'],
                    'event_product_id' => (string) $item['product_id'],
                    'product_code' => (string) $item['product_code_snapshot'],
                    'product_name' => (string) $item['product_name_snapshot'],
                    'unit_price' => number_format((int) $item['unit_price_minor_snapshot'] / 100, 2, '.', ''),
                    'quantity' => (int) $item['quantity'],
                    'line_total' => number_format((int) $item['line_total_minor'] / 100, 2, '.', ''),
                    'version' => (int) $item['version_snapshot'],
                ];
            }
        }

        $data = array_map(static function (array $row) use (&$itemsBySale): array {
            $saleId = (string) $row['id'];
            return [
                'id' => $saleId,
                'organization_id' => (string) $row['organization_id'],
                'event_id' => (string) $row['event_id'],
                'operator_user_id' => (string) $row['operator_user_id'],
                'operator_name' => $row['operator_name'] ?? 'Operador',
                'channel' => (string) $row['channel'],
                'status' => (string) $row['status'],
                'buyer_email' => (string) $row['buyer_email'],
                'buyer_name' => $row['buyer_name'],
                'buyer_phone' => $row['buyer_phone'],
                'subtotal' => number_format((int) $row['subtotal_minor'] / 100, 2, '.', ''),
                'discount_total' => number_format((int) $row['discount_total_minor'] / 100, 2, '.', ''),
                'total' => number_format((int) $row['total_minor'] / 100, 2, '.', ''),
                'currency' => (string) $row['currency'],
                'payment' => [
                    'id' => $row['payment_id'] ?? null,
                    'method' => $row['payment_method'] ?? 'cash',
                    'status' => $row['payment_status'] ?? 'confirmed',
                    'amount' => number_format((int) ($row['payment_amount_minor'] ?? $row['total_minor']) / 100, 2, '.', ''),
                    'currency' => (string) $row['currency'],
                    'confirmed_at' => $row['payment_confirmed_at'],
                ],
                'items' => $itemsBySale[$saleId] ?? [],
                'ticket' => null !== $row['ticket_id'] ? [
                    'id' => (string) $row['ticket_id'],
                    'ticket_number' => (string) $row['ticket_number'],
                    'qr_code' => (string) $row['qr_code'],
                    'status' => (string) $row['ticket_status'],
                ] : null,
                'created_at' => $row['created_at'],
                'confirmed_at' => $row['confirmed_at'],
                'reservation_expires_at' => $row['reservation_expires_at'],
            ];
        }, $sales);

        return new JsonResponse([
            'data' => $data,
            'meta' => $page->metadata($total),
        ]);
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $conditions
     * @param list<mixed> $parameters
     */
    private function addEnumFilter(
        Request $request,
        string $name,
        array $allowed,
        string $column,
        array &$conditions,
        array &$parameters,
    ): void {
        $value = $request->query->get($name);
        if (null === $value || '' === $value) {
            return;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new UnprocessableEntityHttpException($name.' has an invalid value.');
        }
        $conditions[] = $column.' = ?';
        $parameters[] = $value;
    }

    /** @param list<string> $conditions @param list<mixed> $parameters */
    private function addDateFilter(
        Request $request,
        string $name,
        string $condition,
        array &$conditions,
        array &$parameters,
    ): void {
        $value = $request->query->get($name);
        if (null === $value || '' === $value) {
            return;
        }
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException($name.' must be an ISO 8601 date-time.');
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new UnprocessableEntityHttpException($name.' must be an ISO 8601 date-time.');
        }
        $conditions[] = $condition;
        $parameters[] = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
