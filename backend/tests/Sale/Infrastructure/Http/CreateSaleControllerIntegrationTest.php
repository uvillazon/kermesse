<?php

declare(strict_types=1);

namespace App\Tests\Sale\Infrastructure\Http;

use App\Shared\Infrastructure\Security\HmacBearerTokenCodec;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreateSaleControllerIntegrationTest extends WebTestCase
{
    private const ACTOR_ID = 'b1000000-0000-4000-8000-000000000001';
    private const OTHER_USER_ID = 'b1000000-0000-4000-8000-000000000002';
    private const ORGANIZATION_ID = 'b2000000-0000-4000-8000-000000000001';
    private const EVENT_ID = 'b3000000-0000-4000-8000-000000000001';
    private const STAND_ID = 'b4000000-0000-4000-8000-000000000001';
    private const PRODUCT_ID = 'b5000000-0000-4000-8000-000000000001';

    private ?KernelBrowser $client = null;
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $url = getenv('KERMESFERA_INTEGRATION_DATABASE_URL');
        if (false === $url || '' === $url) {
            self::markTestSkipped('Define KERMESFERA_INTEGRATION_DATABASE_URL para ejecutar ventas contra MariaDB.');
        }

        putenv('DATABASE_URL='.$url);
        $_ENV['DATABASE_URL'] = $url;
        $_SERVER['DATABASE_URL'] = $url;
        $this->client = static::createClient();
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->removeFixture();
        $this->insertFixture();
    }

    protected function tearDown(): void
    {
        $this->removeFixture();
        $this->connection?->close();
        $this->client = null;
        parent::tearDown();
    }

    public function testCreatesConfirmedCashSaleAtomicallyAndReplaysIt(): void
    {
        $payload = [
            'buyer_email' => 'buyer@example.test',
            'buyer_name' => 'Comprador',
            'payment_method' => 'cash',
            'items' => [[
                'event_product_id' => self::PRODUCT_ID,
                'quantity' => 2,
            ]],
        ];

        $this->requestSale($payload, 'cash-sale-1');
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'false');
        $first = $this->response();

        self::assertSame('confirmed', $first['data']['status']);
        self::assertSame('50.00', $first['data']['total']);
        self::assertSame('confirmed', $first['data']['payment']['status']);
        self::assertSame('issued', $first['data']['ticket']['status']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first['data']['ticket']['qr_code']);
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT sold FROM products WHERE id = ?', [self::PRODUCT_ID]));
        self::assertSame(1, $this->countRows('sales'));
        self::assertSame(1, $this->countRows('payments'));
        self::assertSame(1, $this->countRows('tickets'));
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_logs WHERE organization_id = ? AND action = ?',
            [self::ORGANIZATION_ID, 'sale.created'],
        ));

        $this->requestSale($payload, 'cash-sale-1');
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        self::assertSame($first, $this->response());
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT sold FROM products WHERE id = ?', [self::PRODUCT_ID]));
        self::assertSame(1, $this->countRows('sales'));
    }

    public function testRejectsUnapprovedPaymentMethodWithoutEffects(): void
    {
        $this->requestSale([
            'buyer_email' => 'buyer@example.test',
            'payment_method' => 'qr_transfer',
            'items' => [['event_product_id' => self::PRODUCT_ID, 'quantity' => 1]],
        ], 'qr-sale-1');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countRows('sales'));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT sold FROM products WHERE id = ?', [self::PRODUCT_ID]));
    }

    public function testCatalogAndSalesListRequireEventPermission(): void
    {
        foreach (['catalog/products', 'sales'] as $path) {
            $this->client()->request(
                'GET',
                '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.self::EVENT_ID.'/'.$path,
                server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken(self::OTHER_USER_ID)],
            );
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testCatalogAndSalesListsApplyServerPaginationAndFilters(): void
    {
        $this->client()->request(
            'GET',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.self::EVENT_ID.'/catalog/products?page=1&page_size=1&search=principal&status=active',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken(self::ACTOR_ID)],
        );
        self::assertResponseIsSuccessful();
        $catalog = $this->response();
        self::assertCount(1, $catalog['data']);
        self::assertSame(self::PRODUCT_ID, $catalog['data'][0]['id']);
        self::assertSame('25.00', $catalog['data'][0]['unit_price']);
        self::assertSame([
            'page' => 1,
            'page_size' => 1,
            'total' => 1,
            'total_pages' => 1,
        ], $catalog['meta']);

        $this->requestSale([
            'buyer_email' => 'filter-buyer@example.test',
            'payment_method' => 'cash',
            'items' => [['event_product_id' => self::PRODUCT_ID, 'quantity' => 1]],
        ], 'cash-sale-filter');
        self::assertResponseStatusCodeSame(201);

        $this->client()->request(
            'GET',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.self::EVENT_ID.'/sales?page=1&page_size=1&status=confirmed&payment_status=confirmed&buyer_email=filter-buyer%40example.test',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken(self::ACTOR_ID)],
        );
        self::assertResponseIsSuccessful();
        $sales = $this->response();
        self::assertCount(1, $sales['data']);
        self::assertSame('filter-buyer@example.test', $sales['data'][0]['buyer_email']);
        self::assertSame('confirmed', $sales['data'][0]['payment']['status']);
        self::assertSame(1, $sales['meta']['total']);
        self::assertSame(1, $sales['meta']['total_pages']);

        $this->client()->request(
            'GET',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.self::EVENT_ID.'/sales?page_size=101',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken(self::ACTOR_ID)],
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testPublicQrOrderReservesStockIdempotentlyAndExpires(): void
    {
        $payload = [
            'buyer_email' => 'public-buyer@example.test',
            'buyer_name' => 'Comprador público',
            'items' => [['event_product_id' => self::PRODUCT_ID, 'quantity' => 2]],
        ];
        $this->requestPublicOrder($payload, 'public-order-1');
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'false');
        $first = $this->response();
        self::assertSame('pending_payment', $first['data']['status']);
        self::assertSame('to_verify', $first['data']['payment_status']);
        self::assertNotEmpty($first['data']['reservation_expires_at']);
        self::assertSame(2, (int) $this->connection()->fetchOne(
            'SELECT sold FROM products WHERE id = ?',
            [self::PRODUCT_ID],
        ));

        $this->requestPublicOrder($payload, 'public-order-1');
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        self::assertSame($first, $this->response());
        self::assertSame(1, $this->countRows('sales'));

        $this->connection()->update('sales', [
            'reservation_expires_at' => '2020-01-01 00:00:00',
        ], ['id' => $first['data']['sale_id']]);
        $this->client()->request('GET', '/api/v1/public/events/sales-event/catalog');
        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->connection()->fetchOne(
            'SELECT sold FROM products WHERE id = ?',
            [self::PRODUCT_ID],
        ));
        self::assertSame('cancelled', $this->connection()->fetchOne(
            'SELECT status FROM sales WHERE id = ?',
            [$first['data']['sale_id']],
        ));
        self::assertSame('cancelled', $this->connection()->fetchOne(
            'SELECT status FROM payments WHERE sale_id = ?',
            [$first['data']['sale_id']],
        ));
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_logs WHERE aggregate_id = ? AND action = ?',
            [$first['data']['sale_id'], 'sale.reservation_expired'],
        ));

        $this->client()->request('GET', '/api/v1/public/events/slug-inexistente/catalog');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPaymentReviewerConfirmsReservedQrOrderWithoutReleasingStock(): void
    {
        $this->requestPublicOrder([
            'buyer_email' => 'confirmed-public@example.test',
            'items' => [['event_product_id' => self::PRODUCT_ID, 'quantity' => 1]],
        ], 'public-order-confirm');
        self::assertResponseStatusCodeSame(201);
        $order = $this->response();

        $this->client()->request(
            'POST',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.self::EVENT_ID.'/sales/'.$order['data']['sale_id'].'/payment/review',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => 'review-public-order-confirm',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken(self::ACTOR_ID),
            ],
            content: json_encode([
                'decision' => 'confirm',
                'provider_reference' => 'QR-TEST-001',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        self::assertSame('confirmed', $this->connection()->fetchOne(
            'SELECT status FROM sales WHERE id = ?',
            [$order['data']['sale_id']],
        ));
        self::assertSame('confirmed', $this->connection()->fetchOne(
            'SELECT status FROM payments WHERE sale_id = ?',
            [$order['data']['sale_id']],
        ));
        self::assertSame('active', $this->connection()->fetchOne(
            'SELECT status FROM tickets WHERE sale_id = ?',
            [$order['data']['sale_id']],
        ));
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT sold FROM products WHERE id = ?',
            [self::PRODUCT_ID],
        ));
    }

    /** @param array<string, mixed> $payload */
    private function requestSale(array $payload, string $idempotencyKey): void
    {
        $this->client()->request(
            'POST',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.self::EVENT_ID.'/sales',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken(self::ACTOR_ID),
            ],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $payload */
    private function requestPublicOrder(array $payload, string $idempotencyKey): void
    {
        $this->client()->request(
            'POST',
            '/api/v1/public/events/sales-event/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
            ],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function insertFixture(): void
    {
        $now = '2026-10-30 18:00:00';
        foreach ([self::ACTOR_ID, self::OTHER_USER_ID] as $index => $userId) {
            $this->connection()->insert('users', [
                'id' => $userId,
                'email' => 'sale-user-'.$index.'@example.test',
                'email_normalized' => 'sale-user-'.$index.'@example.test',
                'display_name' => 'Sale User '.$index,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->connection()->insert('organizations', [
            'id' => self::ORGANIZATION_ID,
            'name' => 'Sales Organization',
            'slug' => 'sales-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([self::ACTOR_ID, self::OTHER_USER_ID] as $index => $userId) {
            $this->connection()->insert('organization_memberships', [
                'id' => sprintf('b2100000-0000-4000-8000-%012d', $index + 1),
                'organization_id' => self::ORGANIZATION_ID,
                'user_id' => $userId,
                'access_type' => 'collaborator',
                'status' => 'active',
                'valid_from' => null,
                'valid_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->connection()->insert('events', [
            'id' => self::EVENT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'name' => 'Sales Event',
            'slug' => 'sales-event',
            'starts_at' => null,
            'ends_at' => null,
            'status' => 'open',
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $eventMembershipId = 'b3100000-0000-4000-8000-000000000001';
        $this->connection()->insert('event_memberships', [
            'id' => $eventMembershipId,
            'event_id' => self::EVENT_ID,
            'user_id' => self::ACTOR_ID,
            'access_type' => 'operational',
            'status' => 'active',
            'valid_from' => null,
            'valid_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach (['SALES_OPERATOR', 'PAYMENT_REVIEWER'] as $roleCode) {
            $roleId = $this->connection()->fetchOne('SELECT id FROM roles WHERE code = ?', [$roleCode]);
            self::assertIsString($roleId);
            $this->connection()->insert('event_membership_roles', [
                'event_membership_id' => $eventMembershipId,
                'role_id' => $roleId,
                'granted_by' => self::ACTOR_ID,
                'granted_at' => $now,
            ]);
        }
        $this->connection()->insert('stands', [
            'id' => self::STAND_ID,
            'event_id' => self::EVENT_ID,
            'name' => 'Comida',
            'manager_name' => 'Responsable',
            'created_at' => $now,
        ]);
        $this->connection()->insert('products', [
            'id' => self::PRODUCT_ID,
            'event_id' => self::EVENT_ID,
            'stand_id' => self::STAND_ID,
            'code' => 'PLATO',
            'name' => 'Plato principal',
            'description' => null,
            'unit_price_minor' => 2500,
            'currency' => 'BOB',
            'stock' => 10,
            'sold' => 0,
            'active' => 1,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function removeFixture(): void
    {
        if (null === $this->connection) {
            return;
        }

        $ticketIds = $this->connection->fetchFirstColumn('SELECT id FROM tickets WHERE event_id = ?', [self::EVENT_ID]);
        foreach ($ticketIds as $ticketId) {
            $this->connection->executeStatement('DELETE FROM ticket_redemptions WHERE ticket_id = ?', [$ticketId]);
            $this->connection->executeStatement('DELETE FROM ticket_items WHERE ticket_id = ?', [$ticketId]);
        }
        $this->connection->executeStatement('DELETE FROM tickets WHERE event_id = ?', [self::EVENT_ID]);
        $this->connection->executeStatement('DELETE FROM payments WHERE event_id = ?', [self::EVENT_ID]);
        $saleIds = $this->connection->fetchFirstColumn('SELECT id FROM sales WHERE event_id = ?', [self::EVENT_ID]);
        foreach ($saleIds as $saleId) {
            $this->connection->executeStatement('DELETE FROM sale_items WHERE sale_id = ?', [$saleId]);
        }
        $this->connection->executeStatement('DELETE FROM sales WHERE event_id = ?', [self::EVENT_ID]);
        $this->connection->delete('audit_logs', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('idempotency_keys', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('products', ['event_id' => self::EVENT_ID]);
        $this->connection->delete('stands', ['event_id' => self::EVENT_ID]);
        $membershipIds = $this->connection->fetchFirstColumn('SELECT id FROM event_memberships WHERE event_id = ?', [self::EVENT_ID]);
        foreach ($membershipIds as $membershipId) {
            $this->connection->delete('event_membership_roles', ['event_membership_id' => $membershipId]);
        }
        $this->connection->delete('event_memberships', ['event_id' => self::EVENT_ID]);
        $this->connection->delete('events', ['id' => self::EVENT_ID]);
        $this->connection->delete('organization_memberships', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('organizations', ['id' => self::ORGANIZATION_ID]);
        $this->connection->delete('users', ['id' => self::ACTOR_ID]);
        $this->connection->delete('users', ['id' => self::OTHER_USER_ID]);
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE event_id = ?', [self::EVENT_ID]);
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        $content = $this->client()->getResponse()->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function bearerToken(string $userId): string
    {
        return (new HmacBearerTokenCodec(
            'test-auth-secret-with-at-least-32-characters',
            'kermesfera-test',
            'kermesfera-api-test',
            0,
            3600,
        ))->issue($userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 300);
    }

    private function client(): KernelBrowser
    {
        return $this->client ?? throw new \LogicException('Client not initialized.');
    }

    private function connection(): Connection
    {
        return $this->connection ?? throw new \LogicException('Connection not initialized.');
    }
}
