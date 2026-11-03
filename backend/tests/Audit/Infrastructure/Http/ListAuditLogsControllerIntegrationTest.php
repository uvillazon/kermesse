<?php

declare(strict_types=1);

namespace App\Tests\Audit\Infrastructure\Http;

use App\Shared\Infrastructure\Security\HmacBearerTokenCodec;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El historial devuelve el nombre de la persona afectada (`subject_name`) para que la interfaz
 * no tenga que mostrar su UUID.
 */
final class ListAuditLogsControllerIntegrationTest extends WebTestCase
{
    private const USER_ID = 'f1000000-0000-4000-8000-000000000001';
    private const TARGET_ID = 'f1000000-0000-4000-8000-000000000002';
    private const ORGANIZATION_ID = 'f2000000-0000-4000-8000-000000000001';
    private const EVENT_ID = 'f4000000-0000-4000-8000-000000000001';

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

    public function testIncludesSubjectNameForActionsOnAnotherPerson(): void
    {
        $this->client()->request(
            'GET',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.self::EVENT_ID.'/audit-logs',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken()],
        );
        self::assertResponseStatusCodeSame(200);
        $content = $this->client()->getResponse()->getContent();
        self::assertIsString($content);
        $logs = [];
        foreach (json_decode($content, true, flags: JSON_THROW_ON_ERROR)['data'] as $log) {
            $logs[$log['action']] = $log;
        }

        self::assertSame('Persona Afectada', $logs['identity.event_access_assigned']['subject_name']);
        self::assertSame('Actor Historial', $logs['identity.event_access_assigned']['actor_name']);
        self::assertNull($logs['expense.created']['subject_name']);
    }

    private function insertFixture(): void
    {
        $now = '2026-10-30 16:00:00';
        foreach ([[self::USER_ID, 'Actor Historial', 'audit-actor'], [self::TARGET_ID, 'Persona Afectada', 'audit-target']] as [$id, $name, $mail]) {
            $this->connection()->insert('users', [
                'id' => $id,
                'email' => $mail.'@example.test',
                'email_normalized' => $mail.'@example.test',
                'display_name' => $name,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->connection()->insert('organizations', [
            'id' => self::ORGANIZATION_ID,
            'name' => 'Audit Organization',
            'slug' => 'audit-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->insert('events', [
            'id' => self::EVENT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'name' => 'Audit Event',
            'slug' => 'audit-event',
            'starts_at' => null,
            'ends_at' => null,
            'status' => 'open',
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            ['f5000000-0000-4000-8000-000000000001', 'identity.event_access_assigned', 'event_membership', ['target_user_id' => self::TARGET_ID, 'previous' => null, 'current' => ['roles' => ['SALES_OPERATOR']]]],
            ['f5000000-0000-4000-8000-000000000002', 'expense.created', 'expense', ['concept' => 'Toldos', 'category' => 'Logística', 'amount_minor' => 1000]],
        ] as [$id, $action, $type, $metadata]) {
            $this->connection()->insert('audit_logs', [
                'id' => $id,
                'organization_id' => self::ORGANIZATION_ID,
                'event_id' => self::EVENT_ID,
                'actor_user_id' => self::USER_ID,
                'action' => $action,
                'aggregate_type' => $type,
                'aggregate_id' => null,
                'request_id' => null,
                'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        }
    }

    private function removeFixture(): void
    {
        if (null === $this->connection) {
            return;
        }

        $this->connection->delete('audit_logs', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('events', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('organizations', ['id' => self::ORGANIZATION_ID]);
        $this->connection->delete('users', ['id' => self::USER_ID]);
        $this->connection->delete('users', ['id' => self::TARGET_ID]);
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
