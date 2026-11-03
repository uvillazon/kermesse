<?php

declare(strict_types=1);

namespace App\Sale\Infrastructure\Doctrine;

use App\Sale\Application\CreateSale\CreatedSale;
use App\Sale\Application\CreateSale\CreateSaleCommand;
use App\Sale\Application\CreateSale\SaleCreationFailed;
use App\Sale\Application\CreateSale\SaleWriteRepository;
use App\Sale\Domain\CatalogOffer;
use App\Sale\Domain\PricedSale;
use App\Shared\Application\Identifier\IdentifierGenerator;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DoctrineSaleWriteRepository implements SaleWriteRepository
{
    public function __construct(
        private Connection $connection,
        private IdentifierGenerator $identifiers,
    ) {
    }

    public function assertOpenEvent(string $organizationId, string $eventId): void
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM events WHERE id = ? AND organization_id = ? FOR UPDATE',
            [$eventId, $organizationId],
        );
        if ('open' !== $status) {
            throw new SaleCreationFailed('Sales require an open event.');
        }
    }

    public function lockCatalogOffers(string $eventId, array $productIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, code, name, unit_price_minor, currency, stock, sold, active, version
             FROM products WHERE event_id = ? AND id IN (?) FOR UPDATE',
            [$eventId, $productIds],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): CatalogOffer => new CatalogOffer(
            (string) $row['id'],
            (string) $row['code'],
            (string) $row['name'],
            (int) $row['unit_price_minor'],
            (string) $row['currency'],
            max(0, (int) $row['stock'] - (int) $row['sold']),
            (bool) $row['active'],
            (int) $row['version'],
        ), $rows);
    }

    public function createSale(
        CreateSaleCommand $command,
        PricedSale $priced,
        ?\DateTimeImmutable $reservationExpiresAt,
    ): CreatedSale
    {
        $saleId = $this->identifiers->generate();
        $paymentId = $this->identifiers->generate();
        $ticketId = $this->identifiers->generate();
        $timestamp = $command->occurredAt->format('Y-m-d H:i:s');
        $confirmed = null === $reservationExpiresAt;
        $saleStatus = $confirmed ? 'confirmed' : 'pending_payment';
        $paymentStatus = $confirmed ? 'confirmed' : 'to_verify';
        $ticketStatus = $confirmed ? 'issued' : 'pending';

        $this->connection->insert('sales', [
            'id' => $saleId,
            'organization_id' => $command->organizationId,
            'event_id' => $command->eventId,
            'operator_user_id' => $command->actorUserId,
            'channel' => $command->channel,
            'status' => $saleStatus,
            'buyer_email' => $command->buyerEmail,
            'buyer_name' => $command->buyerName,
            'buyer_phone' => $command->buyerPhone,
            'subtotal_minor' => $priced->subtotalMinor,
            'discount_total_minor' => $priced->discountTotalMinor,
            'total_minor' => $priced->totalMinor,
            'currency' => $priced->currency,
            'confirmed_at' => $confirmed ? $timestamp : null,
            'reservation_expires_at' => $reservationExpiresAt?->format('Y-m-d H:i:s'),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $responseItems = [];
        $ticketItems = [];
        foreach ($priced->items as $item) {
            $saleItemId = $this->identifiers->generate();
            $ticketItemId = $this->identifiers->generate();
            $this->connection->insert('sale_items', [
                'id' => $saleItemId,
                'sale_id' => $saleId,
                'product_id' => $item->productId,
                'product_code_snapshot' => $item->productCodeSnapshot,
                'product_name_snapshot' => $item->productNameSnapshot,
                'unit_price_minor_snapshot' => $item->unitPriceMinorSnapshot,
                'quantity' => $item->quantity,
                'line_total_minor' => $item->lineTotalMinor,
                'version_snapshot' => $item->catalogVersion,
                'created_at' => $timestamp,
            ]);
            $this->connection->executeStatement(
                'UPDATE products SET sold = sold + ?, updated_at = ? WHERE id = ?',
                [$item->quantity, $timestamp, $item->productId],
            );
            $responseItems[] = [
                'id' => $saleItemId,
                'event_product_id' => $item->productId,
                'product_code' => $item->productCodeSnapshot,
                'product_name' => $item->productNameSnapshot,
                'unit_price' => self::money($item->unitPriceMinorSnapshot),
                'quantity' => $item->quantity,
                'line_total' => self::money($item->lineTotalMinor),
                'version' => $item->catalogVersion,
            ];
            $ticketItems[] = [$ticketItemId, $saleItemId, $item];
        }

        $this->connection->insert('payments', [
            'id' => $paymentId,
            'organization_id' => $command->organizationId,
            'event_id' => $command->eventId,
            'sale_id' => $saleId,
            'method' => $command->paymentMethod,
            'status' => $paymentStatus,
            'amount_minor' => $priced->totalMinor,
            'currency' => $priced->currency,
            'provider_reference' => null,
            'confirmed_at' => $confirmed ? $timestamp : null,
            'created_at' => $timestamp,
        ]);

        $ticketNumber = sprintf('TKT-%s', strtoupper(substr($saleId, 0, 8)));
        $qrCode = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->connection->insert('tickets', [
            'id' => $ticketId,
            'organization_id' => $command->organizationId,
            'event_id' => $command->eventId,
            'sale_id' => $saleId,
            'ticket_number' => $ticketNumber,
            'qr_code' => $qrCode,
            'status' => $ticketStatus,
            'buyer_email' => $command->buyerEmail,
            'buyer_name' => $command->buyerName,
            'issued_at' => $timestamp,
        ]);

        foreach ($ticketItems as [$ticketItemId, $saleItemId, $item]) {
            $this->connection->insert('ticket_items', [
                'id' => $ticketItemId,
                'ticket_id' => $ticketId,
                'sale_item_id' => $saleItemId,
                'product_id' => $item->productId,
                'product_name_snapshot' => $item->productNameSnapshot,
                'quantity' => $item->quantity,
                'redeemed_quantity' => 0,
            ]);
        }

        return new CreatedSale(
            $saleId,
            $ticketNumber,
            $priced->totalMinor,
            $priced->currency,
            [
                'id' => $saleId,
                'organization_id' => $command->organizationId,
                'event_id' => $command->eventId,
                'operator_user_id' => $command->actorUserId,
                'channel' => $command->channel,
                'status' => $saleStatus,
                'buyer_email' => $command->buyerEmail,
                'buyer_name' => $command->buyerName,
                'buyer_phone' => $command->buyerPhone,
                'subtotal' => self::money($priced->subtotalMinor),
                'discount_total' => self::money($priced->discountTotalMinor),
                'total' => self::money($priced->totalMinor),
                'currency' => $priced->currency,
                'payment' => [
                    'id' => $paymentId,
                    'method' => $command->paymentMethod,
                    'status' => $paymentStatus,
                    'amount' => self::money($priced->totalMinor),
                    'currency' => $priced->currency,
                    'confirmed_at' => $confirmed ? $timestamp : null,
                ],
                'items' => $responseItems,
                'ticket' => [
                    'id' => $ticketId,
                    'ticket_number' => $ticketNumber,
                    'qr_code' => $qrCode,
                    'status' => $ticketStatus,
                ],
                'created_at' => $command->occurredAt->format(\DateTimeInterface::ATOM),
                'reservation_expires_at' => $reservationExpiresAt?->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
