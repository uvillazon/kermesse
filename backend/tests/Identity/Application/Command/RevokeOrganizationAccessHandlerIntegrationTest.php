<?php

declare(strict_types=1);

namespace App\Tests\Identity\Application\Command;

use App\Audit\Infrastructure\Doctrine\DoctrineAuditLogWriter;
use App\Identity\Application\Access\AccessScopePolicy;
use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\RolePermissionMap;
use App\Identity\Application\Command\AccessMutationFailed;
use App\Identity\Application\Command\RevokeOrganizationAccessCommand;
use App\Identity\Application\Command\RevokeOrganizationAccessHandler;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventStatus;
use App\Identity\Domain\IdentityStatus;
use App\Identity\Domain\MembershipStatus;
use App\Identity\Domain\OrganizationAccessType;
use App\Identity\Infrastructure\Doctrine\DoctrineAccessDirectory;
use App\Identity\Infrastructure\Doctrine\DoctrineOrganizationAccessWriter;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class RevokeOrganizationAccessHandlerIntegrationTest extends TestCase
{
    private const ACTOR_ID = '61000000-0000-4000-8000-000000000001';
    private const TARGET_ID = '61000000-0000-4000-8000-000000000002';
    private const ORGANIZATION_ID = '62000000-0000-4000-8000-000000000001';
    private const TARGET_MEMBERSHIP_ID = '63000000-0000-4000-8000-000000000002';
    private const EVENT_IDS = [
        '64000000-0000-4000-8000-000000000001',
        '64000000-0000-4000-8000-000000000002',
    ];
    private const EVENT_MEMBERSHIP_IDS = [
        '65000000-0000-4000-8000-000000000001',
        '65000000-0000-4000-8000-000000000002',
    ];
    private const GRANT_IDS = [
        '66000000-0000-4000-8000-000000000001',
        '66000000-0000-4000-8000-000000000002',
    ];
    private const AUDIT_ID = '67000000-0000-4000-8000-000000000001';
    private const SALES_ROLE_ID = '10000000-0000-4000-8000-000000000005';
    private const SALE_PERMISSION_ID = '20000000-0000-4000-8000-000000000008';

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $url = getenv('KERMESFERA_INTEGRATION_DATABASE_URL');
        if (false === $url || '' === $url) {
            self::markTestSkipped('Define KERMESFERA_INTEGRATION_DATABASE_URL para ejecutar integración contra MariaDB.');
        }

        $parts = parse_url($url);
        self::assertIsArray($parts);
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? 3306,
            'user' => $parts['user'] ?? null,
            'password' => $parts['pass'] ?? null,
            'dbname' => ltrim($parts['path'] ?? '', '/'),
            'charset' => 'utf8mb4',
            'serverVersion' => getenv('DATABASE_SERVER_VERSION') ?: '10.4.18-MariaDB',
        ]);
        $this->connection->setNestTransactionsWithSavepoints(true);
        $this->connection->beginTransaction();
        $this->insertFixture();
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection?->close();
    }

    public function testRevokesOrganizationEventsAndDelegationsAtomically(): void
    {
        $membershipId = $this->handler()->handle($this->command());

        self::assertSame(self::TARGET_MEMBERSHIP_ID, $membershipId);
        self::assertSame(MembershipStatus::REVOKED->value, $this->connection()->fetchOne(
            'SELECT status FROM organization_memberships WHERE id = ?',
            [self::TARGET_MEMBERSHIP_ID],
        ));
        self::assertSame(
            [MembershipStatus::REVOKED->value, MembershipStatus::REVOKED->value],
            $this->connection()->fetchFirstColumn(
                'SELECT status FROM event_memberships WHERE user_id = ? ORDER BY id',
                [self::TARGET_ID],
            ),
        );
        self::assertSame(
            [MembershipStatus::REVOKED->value, MembershipStatus::REVOKED->value],
            $this->connection()->fetchFirstColumn(
                'SELECT status FROM event_permission_grants WHERE id IN (?, ?) ORDER BY id',
                self::GRANT_IDS,
            ),
        );
        self::assertSame(IdentityStatus::ACTIVE->value, $this->connection()->fetchOne(
            'SELECT status FROM users WHERE id = ?',
            [self::TARGET_ID],
        ));
        self::assertSame('identity.organization_access_revoked', $this->connection()->fetchOne(
            'SELECT action FROM audit_logs WHERE id = ?',
            [self::AUDIT_ID],
        ));
    }

    public function testRollsBackAllRevocationsWhenAuditCannotBeWritten(): void
    {
        $this->insertConflictingAudit();

        try {
            $this->handler()->handle($this->command());
            self::fail('Expected duplicate audit identifier to fail.');
        } catch (\Throwable) {
            self::assertSame(MembershipStatus::ACTIVE->value, $this->connection()->fetchOne(
                'SELECT status FROM organization_memberships WHERE id = ?',
                [self::TARGET_MEMBERSHIP_ID],
            ));
            self::assertSame(
                [MembershipStatus::ACTIVE->value, MembershipStatus::ACTIVE->value],
                $this->connection()->fetchFirstColumn(
                    'SELECT status FROM event_memberships WHERE user_id = ? ORDER BY id',
                    [self::TARGET_ID],
                ),
            );
            self::assertSame(
                [MembershipStatus::ACTIVE->value, MembershipStatus::ACTIVE->value],
                $this->connection()->fetchFirstColumn(
                    'SELECT status FROM event_permission_grants WHERE id IN (?, ?) ORDER BY id',
                    self::GRANT_IDS,
                ),
            );
        }
    }

    public function testOwnerCannotBeRevokedByGenericCommand(): void
    {
        $this->connection()->update('organization_memberships', [
            'access_type' => OrganizationAccessType::OWNER->value,
        ], ['id' => self::TARGET_MEMBERSHIP_ID]);

        $this->expectException(AccessMutationFailed::class);
        $this->expectExceptionMessage('must be transferred');
        $this->handler()->handle($this->command());
    }

    private function handler(): RevokeOrganizationAccessHandler
    {
        $connection = $this->connection();
        $directory = new DoctrineAccessDirectory($connection);

        return new RevokeOrganizationAccessHandler(
            new TransactionalExecutor($connection),
            new DoctrineOrganizationAccessWriter($connection),
            $directory,
            new AuthorizationPolicy(new AccessScopePolicy($directory), $directory, new RolePermissionMap()),
            new DoctrineAuditLogWriter($connection),
        );
    }

    private function command(): RevokeOrganizationAccessCommand
    {
        return new RevokeOrganizationAccessCommand(
            self::ACTOR_ID,
            self::TARGET_ID,
            self::ORGANIZATION_ID,
            self::AUDIT_ID,
            new \DateTimeImmutable('2026-10-30 13:00:00'),
            'organization-revoke-request',
        );
    }

    private function insertFixture(): void
    {
        $connection = $this->connection();
        $now = '2026-10-30 12:00:00';
        foreach ([self::ACTOR_ID, self::TARGET_ID] as $index => $userId) {
            $connection->insert('users', [
                'id' => $userId,
                'email' => 'organization-revoke-'.$index.'@example.test',
                'email_normalized' => 'organization-revoke-'.$index.'@example.test',
                'display_name' => 'Organization User '.$index,
                'status' => IdentityStatus::ACTIVE->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $connection->insert('organizations', [
            'id' => self::ORGANIZATION_ID,
            'name' => 'Revocation Organization',
            'slug' => 'revocation-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            [self::ACTOR_ID, '63000000-0000-4000-8000-000000000001', OrganizationAccessType::OWNER],
            [self::TARGET_ID, self::TARGET_MEMBERSHIP_ID, OrganizationAccessType::ADMINISTRATOR],
        ] as [$userId, $membershipId, $accessType]) {
            $connection->insert('organization_memberships', [
                'id' => $membershipId,
                'organization_id' => self::ORGANIZATION_ID,
                'user_id' => $userId,
                'access_type' => $accessType->value,
                'status' => MembershipStatus::ACTIVE->value,
                'valid_from' => null,
                'valid_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach (self::EVENT_IDS as $index => $eventId) {
            $connection->insert('events', [
                'id' => $eventId,
                'organization_id' => self::ORGANIZATION_ID,
                'name' => 'Revocation Event '.$index,
                'slug' => 'revocation-event-'.$index,
                'starts_at' => null,
                'ends_at' => null,
                'status' => EventStatus::OPEN->value,
                'closed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $connection->insert('event_memberships', [
                'id' => self::EVENT_MEMBERSHIP_IDS[$index],
                'event_id' => $eventId,
                'user_id' => self::TARGET_ID,
                'access_type' => EventAccessType::OPERATIONAL->value,
                'status' => MembershipStatus::ACTIVE->value,
                'valid_from' => null,
                'valid_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $connection->insert('event_membership_roles', [
                'event_membership_id' => self::EVENT_MEMBERSHIP_IDS[$index],
                'role_id' => self::SALES_ROLE_ID,
                'granted_by' => self::ACTOR_ID,
                'granted_at' => $now,
            ]);
            $connection->insert('event_permission_grants', [
                'id' => self::GRANT_IDS[$index],
                'event_membership_id' => self::EVENT_MEMBERSHIP_IDS[$index],
                'permission_id' => self::SALE_PERMISSION_ID,
                'constraints_json' => null,
                'status' => MembershipStatus::ACTIVE->value,
                'valid_from' => null,
                'valid_until' => null,
                'granted_by' => self::ACTOR_ID,
                'created_at' => $now,
                'revoked_at' => null,
            ]);
        }
    }

    private function insertConflictingAudit(): void
    {
        $this->connection()->insert('audit_logs', [
            'id' => self::AUDIT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'event_id' => null,
            'actor_user_id' => self::ACTOR_ID,
            'action' => 'fixture',
            'aggregate_type' => 'fixture',
            'aggregate_id' => null,
            'request_id' => null,
            'metadata_json' => null,
            'created_at' => '2026-10-30 12:00:00',
        ]);
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }
}
