<?php

declare(strict_types=1);

namespace App\Report\Infrastructure\Http;

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

final readonly class ExpenseController
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
        '/api/v1/organizations/{organizationId}/events/{eventId}/expenses',
        name: 'api_v1_expenses_list',
        methods: ['GET']
    )]
    public function list(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $actorUserId = $this->actors->resolve($request);

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $authDecision = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::EVENT_READ,
            $eventId,
            $occurredAt
        ));

        if (!$authDecision->allowed) {
            throw new AccessDeniedHttpException('No tienes permiso para ver los gastos de este evento.');
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id, e.concept, e.category, e.amount_minor, e.currency, e.notes,
                    e.occurred_at, e.created_at, u.display_name AS recorded_by_name
             FROM expenses e
             JOIN users u ON u.id = e.recorded_by_user_id
             WHERE e.organization_id = ? AND e.event_id = ?
             ORDER BY e.occurred_at DESC, e.created_at DESC',
            [$organizationId, $eventId]
        );

        $data = array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'concept' => (string) $r['concept'],
            'category' => (string) $r['category'],
            'amount' => round((int) $r['amount_minor'] / 100, 2),
            'amount_minor' => (int) $r['amount_minor'],
            'currency' => (string) $r['currency'],
            'notes' => $r['notes'],
            'recorded_by_name' => (string) $r['recorded_by_name'],
            'occurred_at' => (string) $r['occurred_at'],
            'created_at' => (string) $r['created_at'],
        ], $rows);

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'total' => count($data),
                'total_amount' => round(array_sum(array_column($rows, 'amount_minor')) / 100, 2),
            ],
        ]);
    }

    #[Route(
        '/api/v1/organizations/{organizationId}/events/{eventId}/expenses',
        name: 'api_v1_expenses_create',
        methods: ['POST']
    )]
    public function create(Request $request, string $organizationId, string $eventId): JsonResponse
    {
        $this->input->uuid($organizationId, 'organizationId');
        $this->input->uuid($eventId, 'eventId');
        $actorUserId = $this->actors->resolve($request);

        $payload = $this->input->object($request, ['concept', 'category', 'amount_minor', 'notes']);
        $concept = $this->input->requiredString($payload, 'concept', 200);
        $category = $this->input->requiredString($payload, 'category', 64);
        $notes = $this->input->optionalString($payload, 'notes', 500);

        $amountMinor = $payload['amount_minor'] ?? null;
        if (!is_int($amountMinor) || $amountMinor < 50 || $amountMinor > 50000000) {
            throw new UnprocessableEntityHttpException('amount_minor must be an integer >= 50 (in cents).');
        }

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Permitir si tiene EXPENSE_WRITE delegado o EVENT_MANAGE / PAYMENT_REVIEW
        $hasExpensePerm = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::EXPENSE_WRITE,
            $eventId,
            $occurredAt
        ))->allowed;

        $hasManagePerm = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::EVENT_MANAGE,
            $eventId,
            $occurredAt
        ))->allowed;

        $hasFinancePerm = $this->policy->decide(new AuthorizationRequest(
            $actorUserId,
            $organizationId,
            Permission::PAYMENT_REVIEW,
            $eventId,
            $occurredAt
        ))->allowed;

        if (!$hasExpensePerm && !$hasManagePerm && !$hasFinancePerm) {
            throw new AccessDeniedHttpException('No tienes permiso para registrar gastos en este evento.');
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
                $actorUserId,
                $concept,
                $category,
                $amountMinor,
                $notes,
                $occurredAt,
            ): IdempotencyResponse {
                return $this->connection->transactional(function () use (
                    $idempotencyRecordId,
                    $organizationId,
                    $eventId,
                    $actorUserId,
                    $concept,
                    $category,
                    $amountMinor,
                    $notes,
                    $occurredAt,
                ): IdempotencyResponse {
                    $expenseId = $this->identifiers->generate();
                    $nowStr = $occurredAt->format('Y-m-d H:i:s');

                    $this->connection->executeStatement(
                        'INSERT INTO expenses (
                            id, organization_id, event_id, recorded_by_user_id, concept, category,
                            amount_minor, currency, notes, occurred_at, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $expenseId,
                            $organizationId,
                            $eventId,
                            $actorUserId,
                            $concept,
                            $category,
                            $amountMinor,
                            'BOB',
                            $notes,
                            $nowStr,
                            $nowStr,
                        ]
                    );

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
                            'expense.created',
                            'expense',
                            $expenseId,
                            $idempotencyRecordId,
                            json_encode([
                                'concept' => $concept,
                                'category' => $category,
                                'amount_minor' => $amountMinor,
                            ], JSON_THROW_ON_ERROR),
                            $nowStr,
                        ]
                    );

                    $actorName = (string) ($this->connection->fetchOne(
                        'SELECT display_name FROM users WHERE id = ?',
                        [$actorUserId]
                    ) ?: 'Operador');

                    return new IdempotencyResponse(201, [
                        'data' => [
                            'id' => $expenseId,
                            'concept' => $concept,
                            'category' => $category,
                            'amount' => round($amountMinor / 100, 2),
                            'amount_minor' => $amountMinor,
                            'currency' => 'BOB',
                            'notes' => $notes,
                            'recorded_by_name' => $actorName,
                            'occurred_at' => $nowStr,
                            'created_at' => $nowStr,
                        ],
                    ]);
                });
            });
        } catch (HttpExceptionInterface | IdempotencyConflict $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UnprocessableEntityHttpException('Error al registrar el gasto: ' . $e->getMessage());
        }

        return new JsonResponse($result->body, $result->statusCode, [
            'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }
}
