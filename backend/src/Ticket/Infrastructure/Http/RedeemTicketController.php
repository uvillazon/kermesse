<?php

declare(strict_types=1);

namespace App\Ticket\Infrastructure\Http;

use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Domain\Permission;
use App\Shared\Application\Idempotency\IdempotencyConflict;
use App\Shared\Application\Idempotency\IdempotencyResponse;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifier\IdentifierGenerator;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\Http\AuthenticatedActorResolver;
use App\Shared\Infrastructure\Http\HttpIdempotencyRequestFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RedeemTicketController
{
    public function __construct(
        private Connection $connection,
        private AuthorizationPolicy $policy,
        private IdempotentExecutor $idempotency,
        private HttpIdempotencyRequestFactory $idempotencyRequests,
        private ApiRequestInput $input,
        private IdentifierGenerator $identifiers,
        private AuthenticatedActorResolver $actors,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/tickets/{ticketId}/redemptions',
        name: 'api_v1_tickets_redeem',
        methods: ['POST']
    )]
    public function __invoke(Request $request, string $organizationId, string $eventId, string $ticketId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $this->input->uuid($ticketId, 'ticketId');
        $actorUserId = $this->actors->resolve($request);

        $payload = $this->input->object($request, ['items']);
        $rawItems = $payload['items'] ?? null;
        if (!is_array($rawItems) || [] === $rawItems) {
            throw new UnprocessableEntityHttpException('items must be a non-empty array.');
        }

        /** @var list<array{ticket_item_id: string, quantity: int}> $requestedItems */
        $requestedItems = [];
        $seen = [];
        foreach ($rawItems as $index => $item) {
            if (!is_array($item)) {
                throw new UnprocessableEntityHttpException(sprintf('items[%d] must be an object.', $index));
            }
            $ticketItemId = $this->input->uuid(
                $this->input->requiredString($item, 'ticket_item_id'),
                sprintf('items[%d].ticket_item_id', $index)
            );
            if (isset($seen[$ticketItemId])) {
                throw new UnprocessableEntityHttpException('Duplicate ticket_item_id in redemption request.');
            }
            $seen[$ticketItemId] = true;

            $qty = $item['quantity'] ?? null;
            if (!is_int($qty) || $qty < 1 || $qty > 10000) {
                throw new UnprocessableEntityHttpException(sprintf('items[%d].quantity must be an integer >= 1.', $index));
            }
            $requestedItems[] = [
                'ticket_item_id' => $ticketItemId,
                'quantity' => $qty,
            ];
        }

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $authDecision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::TICKET_REDEEM,
            $eventId,
            $occurredAt
        ));

        if (!$authDecision->allowed) {
            throw new AccessDeniedHttpException('No tienes permiso (ticket.redeem) para canjear tickets en este evento.');
        }

        $idempotencyRecordId = $this->identifiers->generate();

        try {
            $result = $this->idempotency->execute($this->idempotencyRequests->create(
                $request,
                $payload,
                $idempotencyRecordId,
                $organizationId,
                $eventId,
                $actorUserId,
                $occurredAt,
            ), function () use (
                $idempotencyRecordId,
                $organizationId,
                $eventId,
                $ticketId,
                $actorUserId,
                $requestedItems,
                $occurredAt,
            ): IdempotencyResponse {
                return $this->connection->transactional(function () use (
                    $idempotencyRecordId,
                    $organizationId,
                    $eventId,
                    $ticketId,
                    $actorUserId,
                    $requestedItems,
                    $occurredAt,
                ): IdempotencyResponse {
                    $ticketRow = $this->connection->fetchAssociative(
                        'SELECT id, ticket_number, qr_code, status, buyer_email, buyer_name
                         FROM tickets
                         WHERE id = ? AND organization_id = ? AND event_id = ?
                         FOR UPDATE',
                        [$ticketId, $organizationId, $eventId]
                    );

                    if (false === $ticketRow) {
                        throw new UnprocessableEntityHttpException('El ticket indicado no existe en este evento.');
                    }

                    $currentStatus = (string) $ticketRow['status'];
                    if (in_array($currentStatus, ['cancelled', 'void'], true)) {
                        throw new UnprocessableEntityHttpException('El ticket se encuentra anulado y no puede ser canjeado.');
                    }
                    if ('pending' === $currentStatus) {
                        throw new UnprocessableEntityHttpException('El pago del ticket aún está pendiente de verificación bancaria.');
                    }
                    if ('redeemed' === $currentStatus) {
                        throw new UnprocessableEntityHttpException('Todos los ítems de este ticket ya fueron canjeados previamente.');
                    }

                    $itemRows = $this->connection->fetchAllAssociative(
                        'SELECT id, product_id, product_name_snapshot, quantity, redeemed_quantity
                         FROM ticket_items
                         WHERE ticket_id = ?
                         FOR UPDATE',
                        [$ticketId]
                    );

                    $itemsById = [];
                    foreach ($itemRows as $row) {
                        $itemsById[(string) $row['id']] = $row;
                    }

                    $nowStr = $occurredAt->format('Y-m-d H:i:s');
                    $redemptionIds = [];
                    $redeemedDetails = [];

                    foreach ($requestedItems as $req) {
                        $itemId = $req['ticket_item_id'];
                        $reqQty = $req['quantity'];

                        if (!isset($itemsById[$itemId])) {
                            throw new UnprocessableEntityHttpException(sprintf('El ítem %s no pertenece a este ticket.', $itemId));
                        }

                        $itemRow = $itemsById[$itemId];
                        $maxQty = (int) $itemRow['quantity'];
                        $alreadyRedeemed = (int) $itemRow['redeemed_quantity'];
                        $remaining = $maxQty - $alreadyRedeemed;

                        if ($reqQty > $remaining) {
                            throw new UnprocessableEntityHttpException(sprintf(
                                'Cantidad excedida para "%s": quedan %d disponibles por entregar.',
                                (string) $itemRow['product_name_snapshot'],
                                $remaining
                            ));
                        }

                        $newRedeemedQty = $alreadyRedeemed + $reqQty;
                        $this->connection->executeStatement(
                            'UPDATE ticket_items SET redeemed_quantity = ? WHERE id = ?',
                            [$newRedeemedQty, $itemId]
                        );
                        $itemsById[$itemId]['redeemed_quantity'] = $newRedeemedQty;

                        $redemptionId = $this->identifiers->generate();
                        $redemptionIds[] = $redemptionId;

                        $this->connection->executeStatement(
                            'INSERT INTO ticket_redemptions (
                                id, ticket_id, ticket_item_id, redeemed_by_user_id, quantity, redeemed_at
                            ) VALUES (?, ?, ?, ?, ?, ?)',
                            [
                                $redemptionId,
                                $ticketId,
                                $itemId,
                                $actorUserId,
                                $reqQty,
                                $nowStr,
                            ]
                        );

                        $redeemedDetails[] = [
                            'redemption_id' => $redemptionId,
                            'ticket_item_id' => $itemId,
                            'product_name' => (string) $itemRow['product_name_snapshot'],
                            'quantity' => $reqQty,
                        ];
                    }

                    // Recalcular estado global del ticket
                    $totalQuantity = 0;
                    $totalRedeemed = 0;
                    foreach ($itemsById as $row) {
                        $totalQuantity += (int) $row['quantity'];
                        $totalRedeemed += (int) $row['redeemed_quantity'];
                    }

                    $newTicketStatus = ($totalQuantity > 0 && $totalRedeemed >= $totalQuantity)
                        ? 'redeemed'
                        : 'partially_redeemed';

                    $this->connection->executeStatement(
                        'UPDATE tickets SET status = ? WHERE id = ?',
                        [$newTicketStatus, $ticketId]
                    );

                    // Registrar auditoría
                    $auditId = $this->identifiers->generate();
                    $this->connection->executeStatement(
                        'INSERT INTO audit_logs (
                            id, organization_id, event_id, actor_user_id, action, aggregate_type, aggregate_id,
                            request_id, metadata_json, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $auditId,
                            $organizationId,
                            $eventId,
                            $actorUserId,
                            'ticket.redeemed',
                            'ticket',
                            $ticketId,
                            $idempotencyRecordId,
                            json_encode([
                                'ticket_number' => $ticketRow['ticket_number'],
                                'status' => $newTicketStatus,
                                'total_quantity' => $totalQuantity,
                                'redeemed_quantity' => $totalRedeemed,
                                'items' => $redeemedDetails,
                            ], JSON_THROW_ON_ERROR),
                            $nowStr,
                        ]
                    );

                    return new IdempotencyResponse(201, [
                        'data' => [
                            'id' => $redemptionIds[0],
                            'ticket_id' => $ticketId,
                            'ticket_number' => (string) $ticketRow['ticket_number'],
                            'status' => $newTicketStatus,
                            'total_quantity' => $totalQuantity,
                            'redeemed_quantity' => $totalRedeemed,
                            'remaining_quantity' => max(0, $totalQuantity - $totalRedeemed),
                            'redemptions' => $redeemedDetails,
                            'redeemed_at' => $occurredAt->format(\DateTimeInterface::ATOM),
                        ],
                    ]);
                });
            });
        } catch (HttpExceptionInterface | IdempotencyConflict $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UnprocessableEntityHttpException('Error al procesar el canje del ticket: ' . $e->getMessage());
        }

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }
}
