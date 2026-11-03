<?php

declare(strict_types=1);

namespace App\Tests\Event\Infrastructure\Http;

use App\Shared\Infrastructure\Security\HmacBearerTokenCodec;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La lista de eventos devuelve la fecha de inicio en ISO 8601 (UTC) y no inventa una fecha
 * para los eventos que no la tienen.
 */
final class ListOrganizationEventsControllerIntegrationTest extends WebTestCase
{
    private const USER_ID = 'e1000000-0000-4000-8000-000000000001';
    private const ORGANIZATION_ID = 'e2000000-0000-4000-8000-000000000001';
    private const EVENT_WITH_DATE = 'e4000000-0000-4000-8000-000000000001';
    private const EVENT_WITHOUT_DATE = 'e4000000-0000-4000-8000-000000000002';

    private ?KernelBrowser $client = null;
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $url = getenv('KERMESFERA_INTEGRATION_DATABASE_URL');
        if (false === $url || '' === $url) {
            self::markTestSkipped('Define KERMESFERA_INTEGRATION_DATABASE_URL para ejecutar integración HTTP contra MariaDB.');
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

    public function testReturnsIsoStartDateAndNullWhenEventHasNoDate(): void
    {
        $this->client()->request(
            'GET',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken()],
        );
        self::assertResponseStatusCodeSame(200);
        $content = $this->client()->getResponse()->getContent();
        self::assertIsString($content);
        $events = [];
        foreach (json_decode($content, true, flags: JSON_THROW_ON_ERROR)['data'] as $event) {
            $events[$event['id']] = $event;
        }

        self::assertSame('2027-03-21T14:00:00+00:00', $events[self::EVENT_WITH_DATE]['starts_at']);
        self::assertNull($events[self::EVENT_WITHOUT_DATE]['starts_at']);
        // Antes devolvía '28 oct 2026' cuando el evento no tenía fecha.
        self::assertNull($events[self::EVENT_WITHOUT_DATE]['date']);
    }

    private function insertFixture(): void
    {
        $now = '2026-10-30 16:00:00';
        $this->connection()->insert('users', [
            'id' => self::USER_ID,
            'email' => 'list-events@example.test',
            'email_normalized' => 'list-events@example.test',
            'display_name' => 'List Events User',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->insert('organizations', [
            'id' => self::ORGANIZATION_ID,
            'name' => 'List Events Organization',
            'slug' => 'list-events-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->insert('organization_memberships', [
            'id' => 'e3000000-0000-4000-8000-000000000001',
            'organization_id' => self::ORGANIZATION_ID,
            'user_id' => self::USER_ID,
            'access_type' => 'owner',
            'status' => 'active',
            'valid_from' => null,
            'valid_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            [self::EVENT_WITH_DATE, 'list-events-dated', '2027-03-21 14:00:00'],
            [self::EVENT_WITHOUT_DATE, 'list-events-undated', null],
        ] as [$eventId, $slug, $startsAt]) {
            $this->connection()->insert('events', [
                'id' => $eventId,
                'organization_id' => self::ORGANIZATION_ID,
                'name' => $slug,
                'slug' => $slug,
                'starts_at' => $startsAt,
                'ends_at' => null,
                'status' => 'draft',
                'closed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function removeFixture(): void
    {
        if (null === $this->connection) {
            return;
        }

        $this->connection->delete('events', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('organization_memberships', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('organizations', ['id' => self::ORGANIZATION_ID]);
        $this->connection->delete('users', ['id' => self::USER_ID]);
    }

    private function bearerToken(): string
    {
        return (new HmacBearerTokenCodec(
            'test-auth-secret-with-at-least-32-characters',
            'kermesfera-test',
            'kermesfera-api-test',
            0,
            3600,
        ))->issue(self::USER_ID, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 300);
    }

    private function client(): KernelBrowser
    {
        self::assertNotNull($this->client);

        return $this->client;
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }
}
