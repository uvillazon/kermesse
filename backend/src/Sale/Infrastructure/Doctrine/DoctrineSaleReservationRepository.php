<?php

declare(strict_types=1);

namespace App\Sale\Infrastructure\Doctrine;

use App\Sale\Application\ExpireReservations\ExpiredReservation;
use App\Sale\Application\ExpireReservations\SaleReservationRepository;
use Doctrine\DBAL\Connection;

final readonly class DoctrineSaleReservationRepository implements SaleReservationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function expireDue(string $eventId, \DateTimeImmutable $now): array
    {
        $timestamp = $now->format('Y-m-d H:i:s');
        $sales = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.organization_id, s.event_id, p.id AS payment_id
             FROM sales s
             INNER JOIN payments p ON p.sale_id = s.id
             WHERE s.event_id = ? AND s.status = ?
               AND s.reservation_expires_at IS NOT NULL AND s.reservation_expires_at <= ?
             ORDER BY s.reservation_expires_at ASC, s.id ASC
             FOR UPDATE',
            [$eventId, 'pending_payment', $timestamp],
        );

        $expired = [];
        foreach ($sales as $sale) {
            $items = $this->connection->fetchAllAssociative(
                'SELECT product_id, quantity FROM sale_items WHERE sale_id = ? ORDER BY product_id ASC',
                [$sale['id']],
            );
            foreach ($items as $item) {
                $this->connection->executeStatement(
                    'UPDATE products
                     SET sold = GREATEST(0, sold - ?), updated_at = ?
                     WHERE id = ? AND event_id = ?',
                    [(int) $item['quantity'], $timestamp, $item['product_id'], $eventId],
                );
            }
            $this->connection->executeStatement(
                'UPDATE sales SET status = ?, updated_at = ? WHERE id = ? AND status = ?',
                ['cancelled', $timestamp, $sale['id'], 'pending_payment'],
            );
            $this->connection->executeStatement(
                'UPDATE payments SET status = ? WHERE id = ? AND status = ?',
                ['cancelled', $sale['payment_id'], 'to_verify'],
            );
            $this->connection->executeStatement(
                'UPDATE tickets SET status = ? WHERE sale_id = ? AND status = ?',
                ['cancelled', $sale['id'], 'pending'],
            );
            $expired[] = new ExpiredReservation(
                (string) $sale['id'],
                (string) $sale['organization_id'],
                (string) $sale['event_id'],
                (string) $sale['payment_id'],
            );
        }

        return $expired;
    }
}
