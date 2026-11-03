<?php

declare(strict_types=1);

namespace App\Ticket\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ApiRequestInput;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListTicketsController
{
    public function __construct(
        private Connection $connection,
        private ApiRequestInput $input,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/tickets',
        name: 'api_v1_tickets_list',
        methods: ['GET']
    )]
    public function __invoke(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');

        $tickets = $this->connection->fetchAllAssociative(
            'SELECT t.id, t.organization_id, t.event_id, t.sale_id, t.ticket_number, t.qr_code,
                    t.status, t.buyer_email, t.buyer_name, t.issued_at,
                    s.total_minor, s.currency, s.status as sale_status,
                    p.method as payment_method, p.status as payment_status
             FROM tickets t
             LEFT JOIN sales s ON s.id = t.sale_id
             LEFT JOIN payments p ON p.sale_id = t.sale_id
             WHERE t.organization_id = ? AND t.event_id = ?
             ORDER BY t.issued_at DESC',
            [$organizationId, $eventId]
        );

        $ticketIds = array_column($tickets, 'id');
        $itemsByTicket = [];
        $redemptionsByTicket = [];

        if ([] !== $ticketIds) {
            $items = $this->connection->fetchAllAssociative(
                'SELECT ti.id, ti.ticket_id, ti.sale_item_id, ti.product_id, ti.product_name_snapshot,
                        ti.quantity, ti.redeemed_quantity,
                        p.stand_id, st.name as stand_name
                 FROM ticket_items ti
                 LEFT JOIN products p ON p.id = ti.product_id
                 LEFT JOIN stands st ON st.id = p.stand_id
                 WHERE ti.ticket_id IN (?)
                 ORDER BY ti.id ASC',
                [$ticketIds],
                [ArrayParameterType::STRING]
            );

            foreach ($items as $item) {
                $qty = (int) $item['quantity'];
                $red = (int) $item['redeemed_quantity'];
                $itemsByTicket[(string) $item['ticket_id']][] = [
                    'id' => (string) $item['id'],
                    'sale_item_id' => (string) $item['sale_item_id'],
                    'product_id' => (string) $item['product_id'],
                    'product_name' => (string) $item['product_name_snapshot'],
                    'stand_id' => $item['stand_id'] ? (string) $item['stand_id'] : null,
                    'stand_name' => $item['stand_name'] ? (string) $item['stand_name'] : 'General',
                    'quantity' => $qty,
                    'redeemed_quantity' => $red,
                    'remaining_quantity' => max(0, $qty - $red),
                ];
            }

            $redemptions = $this->connection->fetchAllAssociative(
                'SELECT tr.id, tr.ticket_id, tr.ticket_item_id, tr.redeemed_by_user_id,
                        tr.quantity, tr.redeemed_at,
                        u.display_name as redeemed_by_name,
                        ti.product_name_snapshot
                 FROM ticket_redemptions tr
                 LEFT JOIN users u ON u.id = tr.redeemed_by_user_id
                 LEFT JOIN ticket_items ti ON ti.id = tr.ticket_item_id
                 WHERE tr.ticket_id IN (?)
                 ORDER BY tr.redeemed_at DESC',
                [$ticketIds],
                [ArrayParameterType::STRING]
            );

            foreach ($redemptions as $r) {
                $redemptionsByTicket[(string) $r['ticket_id']][] = [
                    'id' => (string) $r['id'],
                    'ticket_item_id' => (string) $r['ticket_item_id'],
                    'product_name' => (string) ($r['product_name_snapshot'] ?? 'Producto'),
                    'quantity' => (int) $r['quantity'],
                    'redeemed_by_user_id' => (string) $r['redeemed_by_user_id'],
                    'redeemed_by_name' => (string) ($r['redeemed_by_name'] ?? 'Operador'),
                    'redeemed_at' => (string) $r['redeemed_at'],
                ];
            }
        }

        $data = array_map(static function (array $row) use (&$itemsByTicket, &$redemptionsByTicket): array {
            $ticketId = (string) $row['id'];
            $items = $itemsByTicket[$ticketId] ?? [];
            $totalQuantity = array_sum(array_column($items, 'quantity'));
            $redeemedQuantity = array_sum(array_column($items, 'redeemed_quantity'));

            // Normalizar estado efectivo en función de entregas reales
            $rawStatus = (string) $row['status'];
            if (in_array($rawStatus, ['active', 'issued', 'partially_redeemed'], true)) {
                if ($totalQuantity > 0 && $redeemedQuantity >= $totalQuantity) {
                    $effectiveStatus = 'redeemed';
                } elseif ($redeemedQuantity > 0) {
                    $effectiveStatus = 'partially_redeemed';
                } else {
                    $effectiveStatus = 'active';
                }
            } else {
                $effectiveStatus = $rawStatus;
            }

            return [
                'id' => $ticketId,
                'organization_id' => (string) $row['organization_id'],
                'event_id' => (string) $row['event_id'],
                'sale_id' => (string) $row['sale_id'],
                'ticket_number' => (string) $row['ticket_number'],
                'qr_code' => (string) $row['qr_code'],
                'status' => $effectiveStatus,
                'buyer_email' => (string) $row['buyer_email'],
                'buyer_name' => $row['buyer_name'] ? (string) $row['buyer_name'] : null,
                'total' => number_format((int) ($row['total_minor'] ?? 0) / 100, 2, '.', ''),
                'currency' => (string) ($row['currency'] ?? 'BOB'),
                'payment_method' => (string) ($row['payment_method'] ?? 'cash'),
                'payment_status' => (string) ($row['payment_status'] ?? 'confirmed'),
                'total_quantity' => $totalQuantity,
                'redeemed_quantity' => $redeemedQuantity,
                'remaining_quantity' => max(0, $totalQuantity - $redeemedQuantity),
                'items_summary' => implode(' · ', array_map(
                    static fn (array $i): string => sprintf('%d × %s', $i['quantity'], $i['product_name']),
                    $items
                )),
                'items' => $items,
                'redemptions' => $redemptionsByTicket[$ticketId] ?? [],
                'issued_at' => (string) $row['issued_at'],
            ];
        }, $tickets);

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'page' => 1,
                'page_size' => count($data),
                'total' => count($data),
                'total_pages' => 1,
            ],
        ]);
    }
}
