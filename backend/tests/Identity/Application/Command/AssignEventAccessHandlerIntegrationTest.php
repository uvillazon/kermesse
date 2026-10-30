<?php

declare(strict_types=1);

namespace App\Tests\Identity\Application\Command;

use App\Audit\Infrastructure\Doctrine\DoctrineAuditLogWriter;
use App\Identity\Application\Access\AccessScopePolicy;
use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\RolePermissionMap;
use App\Identity\Application\Command\AssignEventAccessCommand;
use App\Identity\Application\Command\AssignEventAccessHandler;
use App\Identity\Application\Command\ChangeEventRolesCommand;
use App\Identity\Application\Command\ChangeEventRolesHandler;
use App\Identity\Application\Command\RevokeEventAccessCommand;
use App\Identity\Application\Command\RevokeEventAccessHandler;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\EventStatus;
use App\Identity\Domain\IdentityStatus;
use App\Identity\Domain\MembershipStatus;
use App\Identity\Domain\OrganizationAccessType;
use App\Identity\Infrastructure\Doctrine\DoctrineAccessDirectory;
use App\Identity\Infrastructure\Doctrine\DoctrineEventAssignmentWriter;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AssignEventAccessHandlerIntegrationTest extends TestCase
{
    private const ACTOR_ID = '41000000-0000-4000-8000-000000000001';
    private const TARGET_ID = '41000000-0000-4000-8000-000000000002';
    private const ORGANIZATION_ID = '42000000-0000-4000-8000-000000000001';
    private const EVENT_ID = '43000000-0000-4000-8000-000000000001';
    private const EVENT_MEMBERSHIP_ID = '44000000-0000-4000-8000-000000000001';
    private const AUDIT_ID = '45000000-0000-4000-8000-000000000001';
    private const CHANGE_AUDIT_ID = '45000000-0000-4000-8000-000000000002';
    private const REVOKE_AUDIT_ID = '45000000-0000-4000-8000-000000000003';
    private const GRANT_ID = '47000000-0000-4000-8000-000000000001';
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

    public function testAssignsRolesAndAuditAtomically(): void
    {
        $membershipId = $this->handler()->handle($this->command());

        self::assertSame(self::EVENT_MEMBERSHIP_ID, $membershipId);
        self::assertSame('active', $this->connection()->fetchOne(
            'SELECT status FROM event_memberships WHERE id = ?',
            [self::EVENT_MEMBERSHIP_ID],
        ));
        self::assertSame('EVENT_MANAGER', $this->connection()->fetchOne(
            'SELECT r.code FROM event_membership_roles mr INNER JOIN roles r ON r.id = mr.role_id
             WHERE mr.event_membership_id = ?',
            [self::EVENT_MEMBERSHIP_ID],
        ));
        self::assertSame('identity.event_access_assigned', $this->connection()->fetchOne(
            'SELECT action FROM audit_logs WHERE id = ?',
            [self::AUDIT_ID],
        ));
    }

    public function testRollsBackAssignmentWhenAuditCannotBeWritten(): void
    {
        $this->connection()->insert('audit_logs', [
            'id' => self::AUDIT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'event_id' => self::EVENT_ID,
            'actor_user_id' => self::ACTOR_ID,
            'action' => 'fixture',
            'aggregate_type' => 'fixture',
            'aggregate_id' => null,
            'request_id' => null,
            'metadata_json' => null,
            'created_at' => '2026-10-30 12:00:00',
        ]);

        try {
            $this->handler()->handle($this->command());
            self::fail('Expected duplicate audit identifier to fail.');
        } catch (\Throwable) {
            self::assertFalse($this->connection()->fetchOne(
                'SELECT id FROM event_memberships WHERE id = ?',
                [self::EVENT_MEMBERSHIP_ID],
            ));
        }
    }

    public function testChangesRolesAndKeepsAnAuditSnapshot(): void
    {
        $this->handler()->handle($this->command());

        $membershipId = $this->changeHandler()->handle(new ChangeEventRolesCommand(
            self::ACTOR_ID,
            self::TARGET_ID,
            self::ORGANIZATION_ID,
            self::EVENT_ID,
            self::CHANGE_AUDIT_ID,
            [EventRole::SALES_OPERATOR],
            new \DateTimeImmutable('2026-10-30 12:05:00'),
            'change-request',
        ));

        self::assertSame(self::EVENT_MEMBERSHIP_ID, $membershipId);
        self::assertSame(['SALES_OPERATOR'], $this->connection()->fetchFirstColumn(
            'SELECT r.code FROM event_membership_roles mr INNER JOIN roles r ON r.id = mr.role_id
             WHERE mr.event_membership_id = ?',
            [self::EVENT_MEMBERSHIP_ID],
        ));
        $metadata = json_decode((string) $this->connection()->fetchOne(
            'SELECT metadata_json FROM audit_logs WHERE id = ?',
            [self::CHANGE_AUDIT_ID],
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['EVENT_MANAGER'], $metadata['previous_roles']);
        self::assertSame(['SALES_OPERATOR'], $metadata['current_roles']);
    }

    public function testRevokesAssignmentAndActiveDelegations(): void
    {
        $this->handler()->handle($this->command());
        $this->insertActiveGrant();

        $membershipId = $this->revokeHandler()->handle($this->revokeCommand());

        self::assertSame(self::EVENT_MEMBERSHIP_ID, $membershipId);
        self::assertSame(MembershipStatus::REVOKED->value, $this->connection()->fetchOne(
            'SELECT status FROM event_memberships WHERE id = ?',
            [self::EVENT_MEMBERSHIP_ID],
        ));
        self::assertSame(MembershipStatus::REVOKED->value, $this->connection()->fetchOne(
            'SELECT status FROM event_permission_grants WHERE id = ?',
            [self::GRANT_ID],
        ));
        self::assertNotFalse($this->connection()->fetchOne(
            'SELECT revoked_at FROM event_permission_grants WHERE id = ?',
            [self::GRANT_ID],
        ));
        self::assertSame('identity.event_access_revoked', $this->connection()->fetchOne(
            'SELECT action FROM audit_logs WHERE id = ?',
            [self::REVOKE_AUDIT_ID],
        ));
    }

    public function testRollsBackRevocationWhenAuditCannotBeWritten(): void
    {
        $this->handler()->handle($this->command());
        $this->insertActiveGrant();
        $this->connection()->insert('audit_logs', [
            'id' => self::REVOKE_AUDIT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'event_id' => self::EVENT_ID,
            'actor_user_id' => self::ACTOR_ID,
            'action' => 'fixture',
            'aggregate_type' => 'fixture',
            'aggregate_id' => null,
            'request_id' => null,
            'metadata_json' => null,
            'created_at' => '2026-10-30 12:00:00',
        ]);

        try {
            $this->revokeHandler()->handle($this->revokeCommand());
            self::fail('Expected duplicate audit identifier to fail.');
        } catch (\Throwable) {
            self::assertSame(MembershipStatus::ACTIVE->value, $this->connection()->fetchOne(
                'SELECT status FROM event_memberships WHERE id = ?',
                [self::EVENT_MEMBERSHIP_ID],
            ));
            self::assertSame(MembershipStatus::ACTIVE->value, $this->connection()->fetchOne(
                'SELECT status FROM event_permission_grants WHERE id = ?',
                [self::GRANT_ID],
            ));
            self::assertNull($this->connection()->fetchOne(
                'SELECT revoked_at FROM event_permission_grants WHERE id = ?',
                [self::GRANT_ID],
            ));
        }
    }

    private function handler(): AssignEventAccessHandler
    {
        $connection = $this->connection();
        $directory = new DoctrineAccessDirectory($connection);

        return new AssignEventAccessHandler(
            new TransactionalExecutor($connection),
            new DoctrineEventAssignmentWriter($connection),
            $directory,
            new AuthorizationPolicy(new AccessScopePolicy($directory), $directory, new RolePermissionMap()),
            new DoctrineAuditLogWriter($connection),
        );
    }

    private function changeHandler(): ChangeEventRolesHandler
    {
        $connection = $this->connection();
        $directory = new DoctrineAccessDirectory($connection);

        return new ChangeEventRolesHandler(
            new TransactionalExecutor($connection),
            new DoctrineEventAssignmentWriter($connection),
            $directory,
            new AuthorizationPolicy(new AccessScopePolicy($directory), $directory, new RolePermissionMap()),
            new DoctrineAuditLogWriter($connection),
        );
    }

    private function revokeHandler(): RevokeEventAccessHandler
    {
        $connection = $this->connection();
        $directory = new DoctrineAccessDirectory($connection);

        return new RevokeEventAccessHandler(
            new TransactionalExecutor($connection),
            new DoctrineEventAssignmentWriter($connection),
            $directory,
            new AuthorizationPolicy(new AccessScopePolicy($directory), $directory, new RolePermissionMap()),
            new DoctrineAuditLogWriter($connection),
        );
    }

    private function command(): AssignEventAccessCommand
    {
        return new AssignEventAccessCommand(
            self::ACTOR_ID,
            self::TARGET_ID,
            self::ORGANIZATION_ID,
            self::EVENT_ID,
            self::EVENT_MEMBERSHIP_ID,
            self::AUDIT_ID,
            EventAccessType::OPERATIONAL,
            [EventRole::EVENT_MANAGER],
            new \DateTimeImmutable('2026-10-30 12:00:00'),
            requestId: 'integration-request',
        );
    }

    private function revokeCommand(): RevokeEventAccessCommand
    {
        return new RevokeEventAccessCommand(
            self::ACTOR_ID,
            self::TARGET_ID,
            self::ORGANIZATION_ID,
            self::EVENT_ID,
            self::REVOKE_AUDIT_ID,
            new \DateTimeImmutable('2026-10-30 12:10:00'),
            'revoke-request',
        );
    }

    private function insertActiveGrant(): void
    {
        $this->connection()->insert('event_permission_grants', [
            'id' => self::GRANT_ID,
            'event_membership_id' => self::EVENT_MEMBERSHIP_ID,
            'permission_id' => self::SALE_PERMISSION_ID,
            'constraints_json' => null,
            'status' => MembershipStatus::ACTIVE->value,
            'valid_from' => null,
            'valid_until' => null,
            'granted_by' => self::ACTOR_ID,
            'created_at' => '2026-10-30 12:00:00',
            'revoked_at' => null,
        ]);
    }

    private function insertFixture(): void
    {
        $connection = $this->connection();
        $now = '2026-10-30 12:00:00';
        foreach ([self::ACTOR_ID, self::TARGET_ID] as $index => $userId) {
            $connection->insert('users', [
                'id' => $userId,
                'email' => 'command-'.$index.'@example.test',
                'email_normalized' => 'command-'.$index.'@example.test',
                'display_name' => 'Command User '.$index,
                'status' => IdentityStatus::ACTIVE->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $connection->insert('organizations', [
            'id' => self::ORGANIZATION_ID,
            'name' => 'Command Organization',
            'slug' => 'command-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            [self::ACTOR_ID, '46000000-0000-4000-8000-000000000001', OrganizationAccessType::OWNER],
            [self::TARGET_ID, '46000000-0000-4000-8000-000000000002', OrganizationAccessType::COLLABORATOR],
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
        $connection->insert('events', [
            'id' => self::EVENT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'name' => 'Command Event',
            'slug' => 'command-event',
            'starts_at' => null,
            'ends_at' => null,
            'status' => EventStatus::OPEN->value,
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }
}
