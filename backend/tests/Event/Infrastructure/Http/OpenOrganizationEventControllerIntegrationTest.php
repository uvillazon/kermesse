<?php

declare(strict_types=1);

namespace App\Tests\Event\Infrastructure\Http;

use App\Shared\Infrastructure\Security\HmacBearerTokenCodec;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Abrir un evento: solo de borrador a abierto, solo propietario o administrador,
 * con auditoría e idempotencia.
 */
final class OpenOrganizationEventControllerIntegrationTest extends WebTestCase
{
    private const OWNER_ID = 'a7100000-0000-4000-8000-000000000001';
    private const COLLABORATOR_ID = 'a7100000-0000-4000-8000-000000000002';
    private const ORGANIZATION_ID = 'a7200000-0000-4000-8000-000000000001';
    private const OTHER_ORGANIZATION_ID = 'a7200000-0000-4000-8000-000000000002';
    private const DRAFT_EVENT_ID = 'a7400000-0000-4000-8000-000000000001';
    private const CLOSED_EVENT_ID = 'a7400000-0000-4000-8000-000000000002';
    private const OTHER_ORGANIZATION_EVENT_ID = 'a7400000-0000-4000-8000-000000000003';

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

    public function testRequiresAuthentication(): void
    {
        $this->open(self::DRAFT_EVENT_ID, 'open-no-auth', null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('draft', $this->eventStatus(self::DRAFT_EVENT_ID));
    }

    public function testOwnerOpensDraftEventWithAuditAndIdempotentReplay(): void
    {
        $this->open(self::DRAFT_EVENT_ID, 'open-draft-1', self::OWNER_ID);
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'false');
        $first = $this->response();
        self::assertSame('open', $first['data']['status']);
        self::assertSame('2027-03-21T14:00:00+00:00', $first['data']['starts_at']);
        self::assertSame('open', $this->eventStatus(self::DRAFT_EVENT_ID));

        $audit = $this->connection()->fetchAssociative(
            'SELECT actor_user_id, metadata_json FROM audit_logs WHERE event_id = ? AND action = ?',
            [self::DRAFT_EVENT_ID, 'event.opened'],
        );
        self::assertIsArray($audit);
        self::assertSame(self::OWNER_ID, $audit['actor_user_id']);
        self::assertSame(
            ['name' => 'Evento en borrador', 'previous_status' => 'draft', 'status' => 'open'],
            json_decode((string) $audit['metadata_json'], true, flags: JSON_THROW_ON_ERROR),
        );

        // Reintentar con la misma clave devuelve la respuesta guardada y no audita dos veces.
        $this->open(self::DRAFT_EVENT_ID, 'open-draft-1', self::OWNER_ID);
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        self::assertSame($first, $this->response());
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_logs WHERE event_id = ? AND action = ?',
            [self::DRAFT_EVENT_ID, 'event.opened'],
        ));
    }

    public function testOpeningAnAlreadyOpenEventIsAConflict(): void
    {
        $this->open(self::DRAFT_EVENT_ID, 'open-twice-1', self::OWNER_ID);
        self::assertResponseStatusCodeSame(200);

        $this->open(self::DRAFT_EVENT_ID, 'open-twice-2', self::OWNER_ID);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('El evento ya está abierto.', $this->response()['error']['message']);
    }

    public function testClosedEventCannotBeReopened(): void
    {
        $this->open(self::CLOSED_EVENT_ID, 'open-closed', self::OWNER_ID);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Un evento cerrado no se puede volver a abrir.', $this->response()['error']['message']);
        self::assertSame('closed', $this->eventStatus(self::CLOSED_EVENT_ID));
    }

    public function testCollaboratorCannotOpenEvents(): void
    {
        $this->open(self::DRAFT_EVENT_ID, 'open-collaborator', self::COLLABORATOR_ID);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('draft', $this->eventStatus(self::DRAFT_EVENT_ID));
    }

    public function testEventFromAnotherOrganizationIsNotFound(): void
    {
        $this->open(self::OTHER_ORGANIZATION_EVENT_ID, 'open-other-org', self::OWNER_ID);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('draft', $this->eventStatus(self::OTHER_ORGANIZATION_EVENT_ID));
    }

    private function open(string $eventId, string $idempotencyKey, ?string $actorId): void
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey];
        if (null !== $actorId) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->bearerToken($actorId);
        }
        $this->client()->request(
            'POST',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.$eventId.'/opening',
            server: $server,
            content: '{}',
        );
    }

    private function eventStatus(string $eventId): string
    {
        return (string) $this->connection()->fetchOne('SELECT status FROM events WHERE id = ?', [$eventId]);
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

    private function insertFixture(): void
    {
        $now = '2026-11-03 16:00:00';
        foreach ([[self::OWNER_ID, 'open-owner'], [self::COLLABORATOR_ID, 'open-collaborator']] as [$id, $mail]) {
            $this->connection()->insert('users', [
                'id' => $id,
                'email' => $mail.'@example.test',
                'email_normalized' => $mail.'@example.test',
                'display_name' => $mail,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([self::ORGANIZATION_ID => 'open-org', self::OTHER_ORGANIZATION_ID => 'open-other-org'] as $id => $slug) {
            $this->connection()->insert('organizations', [
                'id' => $id,
                'name' => $slug,
                'slug' => $slug,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            ['a7300000-0000-4000-8000-000000000001', self::ORGANIZATION_ID, self::OWNER_ID, 'owner'],
            ['a7300000-0000-4000-8000-000000000002', self::ORGANIZATION_ID, self::COLLABORATOR_ID, 'collaborator'],
        ] as [$id, $organizationId, $userId, $access]) {
            $this->connection()->insert('organization_memberships', [
                'id' => $id,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'access_type' => $access,
                'status' => 'active',
                'valid_from' => null,
                'valid_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            [self::DRAFT_EVENT_ID, self::ORGANIZATION_ID, 'Evento en borrador', 'open-draft', 'draft', '2027-03-21 14:00:00'],
            [self::CLOSED_EVENT_ID, self::ORGANIZATION_ID, 'Evento cerrado', 'open-closed', 'closed', null],
            [self::OTHER_ORGANIZATION_EVENT_ID, self::OTHER_ORGANIZATION_ID, 'Evento ajeno', 'open-foreign', 'draft', null],
        ] as [$id, $organizationId, $name, $slug, $status, $startsAt]) {
            $this->connection()->insert('events', [
                'id' => $id,
                'organization_id' => $organizationId,
                'name' => $name,
                'slug' => $slug,
                'starts_at' => $startsAt,
                'ends_at' => null,
                'status' => $status,
                'closed_at' => 'closed' === $status ? $now : null,
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

        foreach ([self::ORGANIZATION_ID, self::OTHER_ORGANIZATION_ID] as $organizationId) {
            $this->connection->delete('audit_logs', ['organization_id' => $organizationId]);
            $this->connection->delete('idempotency_keys', ['organization_id' => $organizationId]);
            $this->connection->delete('events', ['organization_id' => $organizationId]);
            $this->connection->delete('organization_memberships', ['organization_id' => $organizationId]);
            $this->connection->delete('organizations', ['id' => $organizationId]);
        }
        $this->connection->delete('users', ['id' => self::OWNER_ID]);
        $this->connection->delete('users', ['id' => self::COLLABORATOR_ID]);
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
        self::assertNotNull($this->client);

        return $this->client;
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }
}
