<?php

declare(strict_types=1);

namespace App\Sale\Infrastructure\Http;

use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Domain\Permission;
use App\Sale\Application\CreateSale\CreateSaleCommand;
use App\Sale\Application\CreateSale\CreateSaleHandler;
use App\Sale\Application\CreateSale\SaleCreationFailed;
use App\Sale\Domain\RequestedSaleItem;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Idempotency\IdempotencyResponse;
use App\Shared\Application\Identifier\IdentifierGenerator;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\Http\AuthenticatedActorResolver;
use App\Shared\Infrastructure\Http\HttpIdempotencyRequestFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CreateSaleController
{
    public function __construct(
        private CreateSaleHandler $handler,
        private AuthorizationPolicy $policy,
        private IdempotentExecutor $idempotency,
        private HttpIdempotencyRequestFactory $idempotencyRequests,
        private ApiRequestInput $input,
        private IdentifierGenerator $identifiers,
        private AuthenticatedActorResolver $actors,
    ) {
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/sales',
        name: 'api_v1_sales_create',
        methods: ['POST']
    )]
    public function __invoke(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $actorUserId = $this->actors->resolve($request);
        $payload = $this->input->object($request, [
            'buyer_email', 'buyer_name', 'buyer_phone', 'payment_method', 'items',
        ]);

        $buyerEmail = strtolower($this->input->requiredString($payload, 'buyer_email'));
        if (!filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new UnprocessableEntityHttpException('buyer_email must be a valid email address.');
        }
        $buyerName = $this->optionalTrimmedString($payload, 'buyer_name');
        $buyerPhone = $this->optionalTrimmedString($payload, 'buyer_phone');
        $paymentMethod = $this->input->requiredString($payload, 'payment_method');
        if ('cash' !== $paymentMethod) {
            throw new UnprocessableEntityHttpException('Operator sales currently accept cash only.');
        }
        $items = $this->requestedItems($payload['items'] ?? null);
        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $decision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::SALE_WRITE,
            $eventId,
            $occurredAt,
        ));
        if (!$decision->allowed) {
            throw new AccessDeniedHttpException('No tienes permiso para registrar ventas en este evento.');
        }

        $idempotencyRecordId = $this->identifiers->generate();
        $result = $this->idempotency->execute($this->idempotencyRequests->create(
            $request,
            $payload,
            $idempotencyRecordId,
            $organizationId,
            $eventId,
            $actorUserId,
            $occurredAt,
        ), function () use (
            $organizationId,
            $eventId,
            $actorUserId,
            $buyerEmail,
            $buyerName,
            $buyerPhone,
            $paymentMethod,
            $items,
            $idempotencyRecordId,
            $occurredAt,
        ): IdempotencyResponse {
            try {
                $created = $this->handler->handle(new CreateSaleCommand(
                    $organizationId,
                    $eventId,
                    $actorUserId,
                    $buyerEmail,
                    $buyerName,
                    $buyerPhone,
                    $paymentMethod,
                    'operator',
                    $items,
                    $idempotencyRecordId,
                    $occurredAt,
                ));
            } catch (SaleCreationFailed $failure) {
                throw new UnprocessableEntityHttpException($failure->getMessage(), $failure);
            }

            return new IdempotencyResponse(201, ['data' => $created->responseData]);
        });

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function optionalTrimmedString(array $payload, string $field): ?string
    {
        if (!isset($payload[$field])) {
            return null;
        }
        if (!is_string($payload[$field])) {
            throw new UnprocessableEntityHttpException($field.' must be a string or null.');
        }

        $value = trim($payload[$field]);

        return '' === $value ? null : $value;
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
            if (!is_int($quantity) || $quantity < 1 || $quantity > 10000) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'items[%d].quantity must be an integer between 1 and 10000.',
                    $index,
                ));
            }
            $items[] = new RequestedSaleItem($productId, $quantity);
        }

        return $items;
    }
}
