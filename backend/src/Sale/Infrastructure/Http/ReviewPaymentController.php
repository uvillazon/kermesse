<?php

declare(strict_types=1);

namespace App\Sale\Infrastructure\Http;

use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Domain\Permission;
use App\Sale\Application\ExpireReservations\ExpireSaleReservationsHandler;
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

final readonly class ReviewPaymentController
{
    public function __construct(
        private Connection $connection,
        private AuthorizationPolicy $policy,
        private IdempotentExecutor $idempotency,
        private HttpIdempotencyRequestFactory $idempotencyRequests,
        private ApiRequestInput $input,
        private IdentifierGenerator $identifiers,
        private AuthenticatedActorResolver $actors,
        private ExpireSaleReservationsHandler $expireReservations,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/sales/{saleId}/payment/review',
        name: 'api_v1_sales_payment_review',
        methods: ['POST']
    )]
    public function __invoke(Request $request, string $organizationId, string $eventId, string $saleId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $this->input->uuid($saleId, 'saleId');
        $actorUserId = $this->actors->resolve($request);

        $payload = $this->input->object($request, ['decision', 'provider_reference']);
        $decision = $this->input->requiredString($payload, 'decision');
        if (!in_array($decision, ['confirm', 'reject'], true)) {
            throw new UnprocessableEntityHttpException('decision must be "confirm" or "reject".');
        }
        $providerReference = $this->input->optionalString($payload, 'provider_reference', 128);

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $authDecision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::PAYMENT_REVIEW,
            $eventId,
            $occurredAt
        ));

        if (!$authDecision->allowed) {
            throw new AccessDeniedHttpException('No tienes permiso (payment.review) para verificar pagos en este evento.');
        }
        $this->expireReservations->handle($eventId, $occurredAt);

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
                $saleId,
                $actorUserId,
                $decision,
                $providerReference,
                $occurredAt,
            ): IdempotencyResponse {
                return $this->connection->transactional(function () use (
                    $idempotencyRecordId,
                    $organizationId,
                    $eventId,
                    $saleId,
                    $actorUserId,
                    $decision,
                    $providerReference,
                    $occurredAt,
                ): IdempotencyResponse {
                    $saleRow = $this->connection->fetchAssociative(
                        'SELECT id, status, total_minor, currency
                         FROM sales
                         WHERE id = ? AND organization_id = ? AND event_id = ?
                         FOR UPDATE',
                        [$saleId, $organizationId, $eventId]
                    );

                    if (false === $saleRow) {
                        throw new UnprocessableEntityHttpException('La venta indicada no existe en este evento.');
                    }

                    if ('pending_payment' !== (string) $saleRow['status']) {
                        throw new UnprocessableEntityHttpException('Esta venta ya fue procesada y no está pendiente de verificación.');
                    }

                    $paymentRow = $this->connection->fetchAssociative(
                        'SELECT id, status, method, amount_minor
                         FROM payments
                         WHERE sale_id = ?
                         FOR UPDATE',
                        [$saleId]
                    );

                    if (false === $paymentRow) {
                        throw new UnprocessableEntityHttpException('No se encontró registro de pago para esta venta.');
                    }

                    $nowStr = $occurredAt->format('Y-m-d H:i:s');

                    if ('confirm' === $decision) {
                        $newSaleStatus = 'confirmed';
                        $newPaymentStatus = 'confirmed';
                        $newTicketStatus = 'active';
                        $auditAction = 'payment.confirmed';

                        $this->connection->executeStatement(
                            'UPDATE payments
                             SET status = ?, provider_reference = COALESCE(?, provider_reference), confirmed_at = ?
                             WHERE id = ?',
                            [$newPaymentStatus, $providerReference, $nowStr, $paymentRow['id']]
                        );

                        $this->connection->executeStatement(
                            'UPDATE sales
                             SET status = ?, confirmed_at = ?, reservation_expires_at = NULL, updated_at = ?
                             WHERE id = ?',
                            [$newSaleStatus, $nowStr, $nowStr, $saleId]
                        );

                        $this->connection->executeStatement(
                            'UPDATE tickets SET status = ? WHERE sale_id = ? AND status = ?',
                            [$newTicketStatus, $saleId, 'pending']
                        );
                    } else {
                        $newSaleStatus = 'cancelled';
                        $newPaymentStatus = 'rejected';
                        $newTicketStatus = 'cancelled';
                        $auditAction = 'payment.rejected';

                        $this->connection->executeStatement(
                            'UPDATE payments
                             SET status = ?, provider_reference = COALESCE(?, provider_reference)
                             WHERE id = ?',
                            [$newPaymentStatus, $providerReference, $paymentRow['id']]
                        );

                        $this->connection->executeStatement(
                            'UPDATE sales
                             SET status = ?, reservation_expires_at = NULL, updated_at = ?
                             WHERE id = ?',
                            [$newSaleStatus, $nowStr, $saleId]
                        );

                        $this->connection->executeStatement(
                            'UPDATE tickets SET status = ? WHERE sale_id = ?',
                            [$newTicketStatus, $saleId]
                        );

                        // Devolver stock reservado a los productos del catálogo
                        $saleItems = $this->connection->fetchAllAssociative(
                            'SELECT product_id, quantity FROM sale_items WHERE sale_id = ?',
                            [$saleId]
                        );

                        foreach ($saleItems as $item) {
                            $this->connection->executeStatement(
                                'UPDATE products
                                 SET sold = GREATEST(0, sold - ?), updated_at = ?
                                 WHERE id = ? AND event_id = ?',
                                [(int) $item['quantity'], $nowStr, (string) $item['product_id'], $eventId]
                            );
                        }
                    }

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
                            $auditAction,
                            'sale',
                            $saleId,
                            $idempotencyRecordId,
                            json_encode([
                                'payment_id' => $paymentRow['id'],
                                'decision' => $decision,
                                'sale_status' => $newSaleStatus,
                                'payment_status' => $newPaymentStatus,
                                'provider_reference' => $providerReference,
                            ], JSON_THROW_ON_ERROR),
                            $nowStr,
                        ]
                    );

                    return new IdempotencyResponse(200, [
                        'data' => [
                            'sale_id' => $saleId,
                            'payment_id' => (string) $paymentRow['id'],
                            'decision' => $decision,
                            'sale_status' => $newSaleStatus,
                            'payment_status' => $newPaymentStatus,
                            'reviewed_at' => $occurredAt->format(\DateTimeInterface::ATOM),
                        ],
                    ]);
                });
            });
        } catch (HttpExceptionInterface | IdempotencyConflict $e) {
            throw $e;
        }

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }
}
