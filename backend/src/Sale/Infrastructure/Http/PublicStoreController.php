<?php

declare(strict_types=1);

namespace App\Sale\Infrastructure\Http;

use App\Sale\Application\CreateSale\CreateSaleCommand;
use App\Sale\Application\CreateSale\CreateSaleHandler;
use App\Sale\Application\CreateSale\SaleCreationFailed;
use App\Sale\Application\ExpireReservations\ExpireSaleReservationsHandler;
use App\Sale\Domain\RequestedSaleItem;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Idempotency\IdempotencyResponse;
use App\Shared\Application\Identifier\IdentifierGenerator;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\Http\HttpIdempotencyRequestFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PublicStoreController
{
    public function __construct(
        private Connection $connection,
        private CreateSaleHandler $sales,
        private IdempotentExecutor $idempotency,
        private HttpIdempotencyRequestFactory $idempotencyRequests,
        private ApiRequestInput $input,
        private IdentifierGenerator $identifiers,
        private ExpireSaleReservationsHandler $expireReservations,
    ) {
    }

    #[Route('/api/v1/public/events/{slug}/catalog', name: 'api_v1_public_store_catalog', methods: ['GET'])]
    public function catalog(string $slug): JsonResponse
    {
        $event = $this->event($slug);
        $this->expireReservations->handle(
            (string) $event['id'],
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.stand_id, p.code, p.name, p.description, p.unit_price_minor,
                    p.currency, p.stock, p.sold, p.version, s.name AS stand_name
             FROM products p
             JOIN stands s ON s.id = p.stand_id
             WHERE p.event_id = ? AND p.active = 1
             ORDER BY s.name ASC, p.name ASC',
            [$event['id']],
        );

        return new JsonResponse(['data' => [
            'event' => [
                'id' => (string) $event['id'],
                'organization_id' => (string) $event['organization_id'],
                'organization_name' => (string) $event['organization_name'],
                'slug' => (string) $event['slug'],
                'name' => (string) $event['name'],
                'status' => (string) $event['status'],
                'date' => null === $event['starts_at']
                    ? null
                    : (new \DateTimeImmutable((string) $event['starts_at']))->format('d M Y'),
            ],
            'products' => array_map(static fn (array $row): array => [
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
                'version' => (int) $row['version'],
            ], $rows),
        ]]);
    }

    #[Route('/api/v1/public/events/{slug}/orders', name: 'api_v1_public_store_create_order', methods: ['POST'])]
    public function createOrder(Request $request, string $slug): JsonResponse
    {
        $event = $this->event($slug);
        $this->expireReservations->handle(
            (string) $event['id'],
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        if ('open' !== $event['status']) {
            throw new UnprocessableEntityHttpException('El evento no admite compras en línea en este momento.');
        }
        $operatorUserId = $this->connection->fetchOne(
            'SELECT user_id FROM organization_memberships
             WHERE organization_id = ? AND status = ? ORDER BY created_at ASC LIMIT 1',
            [$event['organization_id'], 'active'],
        );
        if (false === $operatorUserId) {
            throw new UnprocessableEntityHttpException('El evento no tiene un responsable activo para ventas en línea.');
        }

        $payload = $this->input->object($request, ['buyer_email', 'buyer_name', 'items']);
        $buyerEmail = $this->input->email($payload, 'buyer_email');
        $buyerName = $this->input->optionalString($payload, 'buyer_name', 160);
        $items = $this->requestedItems($payload['items'] ?? null);
        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $requestId = $this->identifiers->generate();

        $result = $this->idempotency->execute($this->idempotencyRequests->create(
            $request,
            $payload,
            $requestId,
            (string) $event['organization_id'],
            (string) $event['id'],
            (string) $operatorUserId,
            $occurredAt,
        ), function () use ($event, $operatorUserId, $buyerEmail, $buyerName, $items, $requestId, $occurredAt): IdempotencyResponse {
            try {
                $created = $this->sales->handle(new CreateSaleCommand(
                    (string) $event['organization_id'],
                    (string) $event['id'],
                    (string) $operatorUserId,
                    $buyerEmail,
                    $buyerName,
                    null,
                    'qr_transfer',
                    'online',
                    $items,
                    $requestId,
                    $occurredAt,
                ));
            } catch (SaleCreationFailed $failure) {
                throw new UnprocessableEntityHttpException($failure->getMessage(), $failure);
            }

            $sale = $created->responseData;

            return new IdempotencyResponse(201, ['data' => [
                'sale_id' => $sale['id'],
                'ticket_id' => $sale['ticket']['id'],
                'ticket_number' => $sale['ticket']['ticket_number'],
                'status' => $sale['status'],
                'payment_status' => $sale['payment']['status'],
                'buyer_email' => $sale['buyer_email'],
                'buyer_name' => $sale['buyer_name'],
                'total' => $sale['total'],
                'currency' => $sale['currency'],
                'created_at' => $sale['created_at'],
                'reservation_expires_at' => $sale['reservation_expires_at'],
            ]]);
        });

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }

    /** @return array<string, mixed> */
    private function event(string $slug): array
    {
        $event = $this->connection->fetchAssociative(
            'SELECT e.id, e.organization_id, e.slug, e.name, e.status, e.starts_at,
                    o.name AS organization_name
             FROM events e
             JOIN organizations o ON o.id = e.organization_id
             WHERE e.slug = ? OR e.id = ? LIMIT 1',
            [$slug, $slug],
        );
        if (false === $event) {
            throw new NotFoundHttpException('El evento solicitado no está disponible.');
        }

        return $event;
    }

    /** @return non-empty-list<RequestedSaleItem> */
    private function requestedItems(mixed $rawItems): array
    {
        if (!is_array($rawItems) || [] === $rawItems) {
            throw new UnprocessableEntityHttpException('items must be a non-empty array.');
        }
        $items = [];
        foreach ($rawItems as $index => $item) {
            if (!is_array($item)) {
                throw new UnprocessableEntityHttpException(sprintf('items[%d] must be an object.', $index));
            }
            $productId = $this->input->uuid(
                $this->input->requiredString($item, 'event_product_id'),
                sprintf('items[%d].event_product_id', $index),
            );
            $quantity = $item['quantity'] ?? null;
            if (!is_int($quantity) || $quantity < 1 || $quantity > 100) {
                throw new UnprocessableEntityHttpException(sprintf('items[%d].quantity must be between 1 and 100.', $index));
            }
            $items[] = new RequestedSaleItem($productId, $quantity);
        }

        return $items;
    }
}
