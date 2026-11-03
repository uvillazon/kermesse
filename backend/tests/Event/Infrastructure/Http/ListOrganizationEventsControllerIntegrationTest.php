<?php

declare(strict_types=1);

namespace App\Tests\Event\Infrastructure\Http;

use App\Shared\Infrastructure\Security\HmacBearerTokenCodec;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Autorización de los listados GET por organización/evento y formato de fecha del listado de eventos.
 */
final class ListOrganizationEventsControllerIntegrationTest extends WebTestCase
{
    private const OWNER_ID = 'b1000000-0000-4000-8000-000000000001';
    private const COLLABORATOR_ID = 'b1000000-0000-4000-8000-000000000002';
    private const OUTSIDER_ID = 'b1000000-0000-4000-8000-000000000003';
    private const ORGANIZATION_ID = 'b2000000-0000-4000-8000-000000000001';
    private const OTHER_ORGANIZATION_ID = 'b2000000-0000-4000-8000-000000000002';
    private const OWNER_MEMBERSHIP_ID = 'b3000000-0000-4000-8000-000000000001';
    private const COLLABORATOR_MEMBERSHIP_ID = 'b3000000-0000-4000-8000-000000000002';
    private const OUTSIDER_MEMBERSHIP_ID = 'b3000000-0000-4000-8000-000000000003';
    private const ASSIGNED_EVENT_ID = 'b4000000-0000-4000-8000-000000000001';
    private const UNASSIGNED_EVENT_ID = 'b4000000-0000-4000-8000-000000000002';
    private const OTHER_ORGANIZATION_EVENT_ID = 'b4000000-0000-4000-8000-000000000003';
    private const COLLABORATOR_EVENT_MEMBERSHIP_ID = 'b5000000-0000-4000-8000-000000000001';
    private const SALES_OPERATOR_ROLE_ID = '10000000-0000-4000-8000-000000000005';

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
        $this->client()->request('GET', $this->eventsPath(self::ORGANIZATION_ID));

        self::assertResponseStatusCodeSame(401);
    }

    public function testOwnerListsAllOrganizationEvents(): void
    {
        $this->get($this->eventsPath(self::ORGANIZATION_ID), self::OWNER_ID);

        self::assertResponseStatusCodeSame(200);
        self::assertEqualsCanonicalizing(
            [self::ASSIGNED_EVENT_ID, self::UNASSIGNED_EVENT_ID],
            array_column($this->response()['data'], 'id'),
        );
    }

    public function testReturnsIsoStartDateAndNullWhenEventHasNoDate(): void
    {
        $this->get($this->eventsPath(self::ORGANIZATION_ID), self::OWNER_ID);

        self::assertResponseStatusCodeSame(200);
        $events = array_column($this->response()['data'], null, 'id');
        self::assertSame('2027-03-21T14:00:00+00:00', $events[self::ASSIGNED_EVENT_ID]['starts_at']);
        self::assertNull($events[self::UNASSIGNED_EVENT_ID]['starts_at']);
        // Antes devolvía '28 oct 2026' cuando el evento no tenía fecha.
        self::assertNull($events[self::UNASSIGNED_EVENT_ID]['date']);
    }

    public function testCollaboratorListsOnlyAssignedEvents(): void
    {
        $this->get($this->eventsPath(self::ORGANIZATION_ID), self::COLLABORATOR_ID);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::ASSIGNED_EVENT_ID], array_column($this->response()['data'], 'id'));
    }

    public function testCollaboratorWithRevokedAssignmentListsNoEvents(): void
    {
        $this->connection()->update(
            'event_memberships',
            ['status' => 'revoked'],
            ['id' => self::COLLABORATOR_EVENT_MEMBERSHIP_ID],
        );

        $this->get($this->eventsPath(self::ORGANIZATION_ID), self::COLLABORATOR_ID);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->response()['data']);
    }

    public function testUserFromAnotherOrganizationIsForbidden(): void
    {
        $this->get($this->eventsPath(self::ORGANIZATION_ID), self::OUTSIDER_ID);

        self::assertResponseStatusCodeSame(403);
        $error = $this->response()['error'];
        self::assertSame('request_error', $error['code']);
        self::assertIsString($error['message']);
        self::assertArrayNotHasKey('data', $this->response());
    }

    public function testUserWithRevokedOrganizationMembershipIsForbidden(): void
    {
        $this->connection()->update(
            'organization_memberships',
            ['status' => 'revoked'],
            ['id' => self::COLLABORATOR_MEMBERSHIP_ID],
        );

        $this->get($this->eventsPath(self::ORGANIZATION_ID), self::COLLABORATOR_ID);

        self::assertResponseStatusCodeSame(403);
    }

    /** @return iterable<string, array{string}> */
    public static function eventScopedListings(): iterable
    {
        yield 'tickets' => ['tickets'];
        yield 'members' => ['members'];
        yield 'audit-logs' => ['audit-logs'];
        yield 'catalog stands' => ['catalog/stands'];
        yield 'catalog products' => ['catalog/products'];
        yield 'sales' => ['sales'];
        yield 'expenses' => ['expenses'];
        yield 'course allocations' => ['course-allocations'];
    }

    #[DataProvider('eventScopedListings')]
    public function testUserFromAnotherOrganizationCannotReadEventListings(string $resource): void
    {
        $this->get($this->eventPath(self::ASSIGNED_EVENT_ID, $resource), self::OUTSIDER_ID);

        self::assertResponseStatusCodeSame(403);
        self::assertArrayHasKey('error', $this->response());
    }

    public function testUserFromAnotherOrganizationCannotListInvitations(): void
    {
        $this->get('/api/v1/organizations/'.self::ORGANIZATION_ID.'/invitations', self::OUTSIDER_ID);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCollaboratorCannotListMembersOrInvitations(): void
    {
        $this->get($this->eventPath(self::ASSIGNED_EVENT_ID, 'members'), self::COLLABORATOR_ID);
        self::assertResponseStatusCodeSame(403);

        $this->get('/api/v1/organizations/'.self::ORGANIZATION_ID.'/invitations', self::COLLABORATOR_ID);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerListsMembersAndInvitations(): void
    {
        $this->get($this->eventPath(self::ASSIGNED_EVENT_ID, 'members'), self::OWNER_ID);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(2, $this->response()['data']);

        $this->get('/api/v1/organizations/'.self::ORGANIZATION_ID.'/invitations', self::OWNER_ID);
        self::assertResponseStatusCodeSame(200);
    }

    public function testOwnerCannotListMembersOfAnotherOrganizationsEvent(): void
    {
        $this->get(
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.self::OTHER_ORGANIZATION_EVENT_ID.'/members',
            self::OWNER_ID,
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testSalesOperatorReadsStandsButNotTicketsOrAudit(): void
    {
        $this->get($this->eventPath(self::ASSIGNED_EVENT_ID, 'catalog/stands'), self::COLLABORATOR_ID);
        self::assertResponseStatusCodeSame(200);

        $this->get($this->eventPath(self::ASSIGNED_EVENT_ID, 'tickets'), self::COLLABORATOR_ID);
        self::assertResponseStatusCodeSame(403);

        $this->get($this->eventPath(self::ASSIGNED_EVENT_ID, 'audit-logs'), self::COLLABORATOR_ID);
        self::assertResponseStatusCodeSame(403);
    }

    private function eventsPath(string $organizationId): string
    {
        return '/api/v1/organizations/'.$organizationId.'/events';
    }

    private function eventPath(string $eventId, string $resource): string
    {
        return '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.$eventId.'/'.$resource;
    }

    private function get(string $path, string $actorId): void
    {
        $this->client()->request('GET', $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken($actorId),
        ]);
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
        $now = '2026-10-30 16:00:00';
        foreach ([self::OWNER_ID, self::COLLABORATOR_ID, self::OUTSIDER_ID] as $index => $userId) {
            $this->connection()->insert('users', [
                'id' => $userId,
                'email' => 'list-events-'.$index.'@example.test',
                'email_normalized' => 'list-events-'.$index.'@example.test',
                'display_name' => 'List Events User '.$index,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([self::ORGANIZATION_ID => 'list-events-org', self::OTHER_ORGANIZATION_ID => 'list-events-other-org'] as $organizationId => $slug) {
            $this->connection()->insert('organizations', [
                'id' => $organizationId,
                'name' => $slug,
                'slug' => $slug,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            [self::OWNER_MEMBERSHIP_ID, self::ORGANIZATION_ID, self::OWNER_ID, 'owner'],
            [self::COLLABORATOR_MEMBERSHIP_ID, self::ORGANIZATION_ID, self::COLLABORATOR_ID, 'collaborator'],
            [self::OUTSIDER_MEMBERSHIP_ID, self::OTHER_ORGANIZATION_ID, self::OUTSIDER_ID, 'owner'],
        ] as [$membershipId, $organizationId, $userId, $accessType]) {
            $this->connection()->insert('organization_memberships', [
                'id' => $membershipId,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'access_type' => $accessType,
                'status' => 'active',
                'valid_from' => null,
                'valid_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            [self::ASSIGNED_EVENT_ID, self::ORGANIZATION_ID, 'list-events-assigned', '2026-10-30 16:00:02', '2027-03-21 14:00:00'],
            [self::UNASSIGNED_EVENT_ID, self::ORGANIZATION_ID, 'list-events-unassigned', '2026-10-30 16:00:01', null],
            [self::OTHER_ORGANIZATION_EVENT_ID, self::OTHER_ORGANIZATION_ID, 'list-events-foreign', '2026-10-30 16:00:00', null],
        ] as [$eventId, $organizationId, $slug, $createdAt, $startsAt]) {
            $this->connection()->insert('events', [
                'id' => $eventId,
                'organization_id' => $organizationId,
                'name' => $slug,
                'slug' => $slug,
                'starts_at' => $startsAt,
                'ends_at' => null,
                'status' => 'open',
                'closed_at' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
        $this->connection()->insert('event_memberships', [
            'id' => self::COLLABORATOR_EVENT_MEMBERSHIP_ID,
            'event_id' => self::ASSIGNED_EVENT_ID,
            'user_id' => self::COLLABORATOR_ID,
            'access_type' => 'operational',
            'status' => 'active',
            'valid_from' => null,
            'valid_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->insert('event_membership_roles', [
            'event_membership_id' => self::COLLABORATOR_EVENT_MEMBERSHIP_ID,
            'role_id' => self::SALES_OPERATOR_ROLE_ID,
            'granted_by' => self::OWNER_ID,
            'granted_at' => $now,
        ]);
    }

    private function removeFixture(): void
    {
        if (null === $this->connection) {
            return;
        }

        $this->connection->delete('event_membership_roles', ['event_membership_id' => self::COLLABORATOR_EVENT_MEMBERSHIP_ID]);
        foreach ([self::ASSIGNED_EVENT_ID, self::UNASSIGNED_EVENT_ID, self::OTHER_ORGANIZATION_EVENT_ID] as $eventId) {
            $this->connection->delete('event_memberships', ['event_id' => $eventId]);
            $this->connection->delete('events', ['id' => $eventId]);
        }
        foreach ([self::ORGANIZATION_ID, self::OTHER_ORGANIZATION_ID] as $organizationId) {
            $this->connection->delete('organization_memberships', ['organization_id' => $organizationId]);
            $this->connection->delete('organizations', ['id' => $organizationId]);
        }
        foreach ([self::OWNER_ID, self::COLLABORATOR_ID, self::OUTSIDER_ID] as $userId) {
            $this->connection->delete('users', ['id' => $userId]);
        }
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

    private function bearerToken(string $actorId): string
    {
        return (new HmacBearerTokenCodec(
            'test-auth-secret-with-at-least-32-characters',
            'kermesfera-test',
            'kermesfera-api-test',
            0,
            3600,
        ))->issue($actorId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 300);
    }
}
