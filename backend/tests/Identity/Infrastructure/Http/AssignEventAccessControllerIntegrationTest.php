<?php

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Http;

use App\Identity\Infrastructure\Security\SecureInvitationTokenGenerator;
use App\Shared\Infrastructure\Security\HmacBearerTokenCodec;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AssignEventAccessControllerIntegrationTest extends WebTestCase
{
    private const ACTOR_ID = 'a1000000-0000-4000-8000-000000000001';
    private const TARGET_ID = 'a1000000-0000-4000-8000-000000000002';
    private const ORGANIZATION_ID = 'a2000000-0000-4000-8000-000000000001';
    private const ACTOR_MEMBERSHIP_ID = 'a3000000-0000-4000-8000-000000000001';
    private const TARGET_MEMBERSHIP_ID = 'a3000000-0000-4000-8000-000000000002';
    private const EVENT_ID = 'a4000000-0000-4000-8000-000000000001';

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

    public function testRequiresAuthenticatedActor(): void
    {
        $this->requestAssignment(['roles' => ['SALES_OPERATOR']], 'missing-auth-key', authenticated: false);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertSame('request_error', $this->response()['error']['code']);
    }

    public function testAssignsEventAccessAndReplaysSameIdempotentRequest(): void
    {
        $payload = ['roles' => ['SALES_OPERATOR', 'TICKET_OPERATOR']];

        $this->requestAssignment($payload, 'http-assignment-key-1');
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'false');
        $first = $this->response();
        $membershipId = $first['data']['event_membership_id'];
        self::assertIsString($membershipId);

        $this->requestAssignment($payload, 'http-assignment-key-1');
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        self::assertSame($first, $this->response());
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM event_memberships WHERE event_id = ? AND user_id = ?',
            [self::EVENT_ID, self::TARGET_ID],
        ));
        self::assertSame(2, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM event_membership_roles WHERE event_membership_id = ?',
            [$membershipId],
        ));
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_logs WHERE event_id = ? AND action = ?',
            [self::EVENT_ID, 'identity.event_access_assigned'],
        ));
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM idempotency_keys WHERE organization_id = ? AND idempotency_key = ?',
            [self::ORGANIZATION_ID, 'http-assignment-key-1'],
        ));

        $this->requestAssignment(['roles' => ['REPORT_VIEWER']], 'http-assignment-key-1');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('idempotency_conflict', $this->response()['error']['code']);
        self::assertSame(2, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM event_membership_roles WHERE event_membership_id = ?',
            [$membershipId],
        ));
    }

    public function testChangesRolesIdempotently(): void
    {
        $this->requestAssignment(['roles' => ['SALES_OPERATOR']], 'roles-assignment-key');
        self::assertResponseStatusCodeSame(201);
        $membershipId = $this->response()['data']['event_membership_id'];
        self::assertIsString($membershipId);

        $this->requestRoles(['REPORT_VIEWER'], 'change-roles-key-1');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'false');
        $first = $this->response();
        self::assertSame(['REPORT_VIEWER'], $first['data']['roles']);

        $this->requestRoles(['REPORT_VIEWER'], 'change-roles-key-1');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        self::assertSame($first, $this->response());
        self::assertSame(['REPORT_VIEWER'], $this->connection()->fetchFirstColumn(
            'SELECT r.code FROM event_membership_roles emr INNER JOIN roles r ON r.id = emr.role_id
             WHERE emr.event_membership_id = ?',
            [$membershipId],
        ));
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_logs WHERE event_id = ? AND action = ?',
            [self::EVENT_ID, 'identity.event_roles_changed'],
        ));

        $this->requestRoles(['TICKET_OPERATOR'], 'change-roles-key-1');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('idempotency_conflict', $this->response()['error']['code']);
    }

    public function testRevokesEventAccessAndDelegationsIdempotently(): void
    {
        $this->requestAssignment(['roles' => ['SALES_OPERATOR']], 'revoke-assignment-key');
        self::assertResponseStatusCodeSame(201);
        $membershipId = $this->response()['data']['event_membership_id'];
        self::assertIsString($membershipId);
        $this->insertDelegation($membershipId);

        $this->requestRevocation('revoke-event-access-key-1');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'false');
        $first = $this->response();
        self::assertSame('revoked', $first['data']['status']);

        $this->requestRevocation('revoke-event-access-key-1');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        self::assertSame($first, $this->response());
        self::assertSame('revoked', $this->connection()->fetchOne(
            'SELECT status FROM event_memberships WHERE id = ?',
            [$membershipId],
        ));
        self::assertSame('revoked', $this->connection()->fetchOne(
            'SELECT status FROM event_permission_grants WHERE event_membership_id = ?',
            [$membershipId],
        ));
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_logs WHERE event_id = ? AND action = ?',
            [self::EVENT_ID, 'identity.event_access_revoked'],
        ));
    }

    public function testRevokesOrganizationAccessAndAllEventDelegationsIdempotently(): void
    {
        $this->requestAssignment(['roles' => ['SALES_OPERATOR']], 'organization-revoke-assignment-key');
        self::assertResponseStatusCodeSame(201);
        $eventMembershipId = $this->response()['data']['event_membership_id'];
        self::assertIsString($eventMembershipId);
        $this->insertDelegation($eventMembershipId);

        $this->requestOrganizationRevocation('organization-revoke-key-1');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'false');
        $first = $this->response();
        self::assertSame(self::TARGET_MEMBERSHIP_ID, $first['data']['organization_membership_id']);
        self::assertSame('revoked', $first['data']['status']);

        $this->requestOrganizationRevocation('organization-revoke-key-1');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        self::assertSame($first, $this->response());
        self::assertSame('revoked', $this->connection()->fetchOne(
            'SELECT status FROM organization_memberships WHERE id = ?',
            [self::TARGET_MEMBERSHIP_ID],
        ));
        self::assertSame('revoked', $this->connection()->fetchOne(
            'SELECT status FROM event_memberships WHERE id = ?',
            [$eventMembershipId],
        ));
        self::assertSame('revoked', $this->connection()->fetchOne(
            'SELECT status FROM event_permission_grants WHERE event_membership_id = ?',
            [$eventMembershipId],
        ));
        self::assertSame('active', $this->connection()->fetchOne(
            'SELECT status FROM users WHERE id = ?',
            [self::TARGET_ID],
        ));
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_logs WHERE organization_id = ? AND action = ?',
            [self::ORGANIZATION_ID, 'identity.organization_access_revoked'],
        ));
    }

    public function testTransfersOrganizationOwnershipIdempotently(): void
    {
        $this->requestOwnershipTransfer('ownership-transfer-key-1');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'false');
        $first = $this->response();
        self::assertSame(self::TARGET_MEMBERSHIP_ID, $first['data']['new_owner_membership_id']);
        self::assertSame(self::TARGET_ID, $first['data']['new_owner_user_id']);
        self::assertSame('administrator', $first['data']['previous_owner_access_type']);
        self::assertSame('transferred', $first['data']['status']);

        $this->requestOwnershipTransfer('ownership-transfer-key-1');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        self::assertSame($first, $this->response());
        self::assertSame('administrator', $this->connection()->fetchOne(
            'SELECT access_type FROM organization_memberships WHERE id = ?',
            [self::ACTOR_MEMBERSHIP_ID],
        ));
        self::assertSame('owner', $this->connection()->fetchOne(
            'SELECT access_type FROM organization_memberships WHERE id = ?',
            [self::TARGET_MEMBERSHIP_ID],
        ));
        self::assertSame('ORG_ADMIN', $this->connection()->fetchOne(
            'SELECT r.code FROM organization_membership_roles omr
             INNER JOIN roles r ON r.id = omr.role_id
             WHERE omr.organization_membership_id = ?',
            [self::ACTOR_MEMBERSHIP_ID],
        ));
        self::assertSame('ORG_OWNER', $this->connection()->fetchOne(
            'SELECT r.code FROM organization_membership_roles omr
             INNER JOIN roles r ON r.id = omr.role_id
             WHERE omr.organization_membership_id = ?',
            [self::TARGET_MEMBERSHIP_ID],
        ));
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_logs WHERE organization_id = ? AND action = ?',
            [self::ORGANIZATION_ID, 'identity.organization_ownership_transferred'],
        ));
    }

    public function testQueuesInvitationWithoutPersistingPlainTokenAndReplaysResponse(): void
    {
        $payload = [
            'event_id' => self::EVENT_ID,
            'email' => 'queued-invitation@example.test',
            'target_access_type' => 'operational',
            'roles' => ['SALES_OPERATOR'],
            'expires_at' => '2026-11-01T18:00:00+00:00',
        ];

        $this->requestInvitation($payload, 'create-invitation-http-key-1');
        self::assertResponseStatusCodeSame(202);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'false');
        $first = $this->response();
        self::assertSame('queued', $first['data']['delivery_status']);
        self::assertArrayNotHasKey('token', $first['data']);

        $this->requestInvitation($payload, 'create-invitation-http-key-1');
        self::assertResponseStatusCodeSame(202);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        self::assertSame($first, $this->response());
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM access_invitations WHERE organization_id = ? AND email_normalized = ?',
            [self::ORGANIZATION_ID, 'queued-invitation@example.test'],
        ));
        $delivery = $this->connection()->fetchAssociative(
            'SELECT payload_json, token_key_version, status FROM notification_deliveries
             WHERE organization_id = ? AND recipient = ?',
            [self::ORGANIZATION_ID, 'queued-invitation@example.test'],
        );
        self::assertIsArray($delivery);
        self::assertSame('pending', $delivery['status']);
        self::assertSame('v1', $delivery['token_key_version']);
        $deliveryPayload = json_decode((string) $delivery['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['invitation_id'], array_keys($deliveryPayload));
        $storedResponse = (string) $this->connection()->fetchOne(
            'SELECT response_json FROM idempotency_keys WHERE organization_id = ? AND idempotency_key = ?',
            [self::ORGANIZATION_ID, 'create-invitation-http-key-1'],
        );
        self::assertStringNotContainsString('token', $storedResponse);
    }

    public function testAcceptsInvitationWithoutBearerAndRejectsReplay(): void
    {
        $email = 'accepted-invitation@example.test';
        $this->requestInvitation([
            'event_id' => self::EVENT_ID,
            'email' => $email,
            'target_access_type' => 'operational',
            'roles' => ['SALES_OPERATOR'],
            'expires_at' => '2026-11-01T18:00:00+00:00',
        ], 'accept-invitation-fixture-key');
        self::assertResponseStatusCodeSame(202);
        $invitationId = $this->response()['data']['invitation_id'];
        self::assertIsString($invitationId);
        $token = (new SecureInvitationTokenGenerator(
            'test-invitation-secret-with-at-least-32-characters',
            'v1',
        ))->generate($invitationId);

        $this->requestAcceptance($token, $email);
        self::assertResponseStatusCodeSame(200);
        $response = $this->response();
        self::assertSame('accepted', $response['data']['status']);
        $userId = $response['data']['user_id'];
        self::assertIsString($userId);
        self::assertSame('accepted', $this->connection()->fetchOne(
            'SELECT status FROM access_invitations WHERE id = ?',
            [$invitationId],
        ));
        self::assertSame('SALES_OPERATOR', $this->connection()->fetchOne(
            'SELECT r.code FROM event_memberships em
             INNER JOIN event_membership_roles emr ON emr.event_membership_id = em.id
             INNER JOIN roles r ON r.id = emr.role_id
             WHERE em.event_id = ? AND em.user_id = ?',
            [self::EVENT_ID, $userId],
        ));

        $this->requestAcceptance($token, $email);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('access_mutation_failed', $this->response()['error']['code']);
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM event_memberships WHERE event_id = ? AND user_id = ?',
            [self::EVENT_ID, $userId],
        ));
    }

    /** @param array{roles: list<string>} $changes */
    private function requestAssignment(array $changes, string $idempotencyKey, bool $authenticated = true): void
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
        ];
        if ($authenticated) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->bearerToken();
        }
        $this->client()->request(
            'POST',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.self::EVENT_ID.'/access-assignments',
            server: $server,
            content: json_encode([
                'target_user_id' => self::TARGET_ID,
                'access_type' => 'operational',
                ...$changes,
            ], JSON_THROW_ON_ERROR),
        );
    }

    /** @param list<string> $roles */
    private function requestRoles(array $roles, string $idempotencyKey): void
    {
        $this->client()->request(
            'PATCH',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.self::EVENT_ID.'/access-assignments/'.self::TARGET_ID.'/roles',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken(),
            ],
            content: json_encode(['roles' => $roles], JSON_THROW_ON_ERROR),
        );
    }

    private function requestRevocation(string $idempotencyKey): void
    {
        $this->client()->request(
            'DELETE',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/events/'.self::EVENT_ID.'/access-assignments/'.self::TARGET_ID,
            server: [
                'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken(),
            ],
        );
    }

    private function requestOrganizationRevocation(string $idempotencyKey): void
    {
        $this->client()->request(
            'DELETE',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/access-assignments/'.self::TARGET_ID,
            server: [
                'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken(),
            ],
        );
    }

    private function requestOwnershipTransfer(string $idempotencyKey): void
    {
        $this->client()->request(
            'POST',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/ownership-transfers',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken(),
            ],
            content: json_encode([
                'new_owner_user_id' => self::TARGET_ID,
                'previous_owner_access_type' => 'administrator',
            ], JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $payload */
    private function requestInvitation(array $payload, string $idempotencyKey): void
    {
        $this->client()->request(
            'POST',
            '/api/v1/organizations/'.self::ORGANIZATION_ID.'/invitations',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken(),
            ],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function requestAcceptance(string $token, string $email): void
    {
        $this->client()->request(
            'POST',
            '/api/v1/invitations/accept',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'token' => $token,
                'email' => $email,
                'display_name' => 'Accepted Invitation User',
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function insertDelegation(string $membershipId): void
    {
        $permissionId = $this->connection()->fetchOne('SELECT id FROM permissions WHERE code = ?', ['sale.write']);
        self::assertIsString($permissionId);
        $this->connection()->insert('event_permission_grants', [
            'id' => 'a5000000-0000-4000-8000-000000000001',
            'event_membership_id' => $membershipId,
            'permission_id' => $permissionId,
            'constraints_json' => null,
            'status' => 'active',
            'valid_from' => null,
            'valid_until' => null,
            'granted_by' => self::ACTOR_ID,
            'created_at' => '2026-10-30 16:30:00',
            'revoked_at' => null,
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
        foreach ([self::ACTOR_ID, self::TARGET_ID] as $index => $userId) {
            $this->connection()->insert('users', [
                'id' => $userId,
                'email' => 'http-assignment-'.$index.'@example.test',
                'email_normalized' => 'http-assignment-'.$index.'@example.test',
                'display_name' => 'HTTP Assignment User '.$index,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->connection()->insert('organizations', [
            'id' => self::ORGANIZATION_ID,
            'name' => 'HTTP Assignment Organization',
            'slug' => 'http-assignment-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            [self::ACTOR_MEMBERSHIP_ID, self::ACTOR_ID, 'owner'],
            [self::TARGET_MEMBERSHIP_ID, self::TARGET_ID, 'collaborator'],
        ] as [$membershipId, $userId, $accessType]) {
            $this->connection()->insert('organization_memberships', [
                'id' => $membershipId,
                'organization_id' => self::ORGANIZATION_ID,
                'user_id' => $userId,
                'access_type' => $accessType,
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
            'name' => 'HTTP Assignment Event',
            'slug' => 'http-assignment-event',
            'starts_at' => null,
            'ends_at' => null,
            'status' => 'open',
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function removeFixture(): void
    {
        if (null === $this->connection) {
            return;
        }

        $membershipIds = $this->connection->fetchFirstColumn(
            'SELECT id FROM event_memberships WHERE event_id = ?',
            [self::EVENT_ID],
        );
        foreach ($membershipIds as $membershipId) {
            $this->connection->delete('event_permission_grants', ['event_membership_id' => $membershipId]);
            $this->connection->delete('event_membership_roles', ['event_membership_id' => $membershipId]);
        }
        $this->connection->delete('notification_deliveries', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('access_invitations', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('audit_logs', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('idempotency_keys', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('event_memberships', ['event_id' => self::EVENT_ID]);
        $this->connection->delete('events', ['id' => self::EVENT_ID]);
        $this->connection->delete('organization_membership_roles', [
            'organization_membership_id' => self::ACTOR_MEMBERSHIP_ID,
        ]);
        $this->connection->delete('organization_membership_roles', [
            'organization_membership_id' => self::TARGET_MEMBERSHIP_ID,
        ]);
        $this->connection->delete('organization_memberships', ['organization_id' => self::ORGANIZATION_ID]);
        $this->connection->delete('organizations', ['id' => self::ORGANIZATION_ID]);
        $this->connection->delete('users', ['id' => self::ACTOR_ID]);
        $this->connection->delete('users', ['id' => self::TARGET_ID]);
        $this->connection->delete('users', ['email_normalized' => 'accepted-invitation@example.test']);
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

    private function bearerToken(): string
    {
        return (new HmacBearerTokenCodec(
            'test-auth-secret-with-at-least-32-characters',
            'kermesfera-test',
            'kermesfera-api-test',
            0,
            3600,
        ))->issue(self::ACTOR_ID, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 300);
    }
}
