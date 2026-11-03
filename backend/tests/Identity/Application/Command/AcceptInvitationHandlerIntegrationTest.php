<?php

declare(strict_types=1);

namespace App\Tests\Identity\Application\Command;

use App\Audit\Infrastructure\Doctrine\DoctrineAuditLogWriter;
use App\Identity\Application\Command\AcceptInvitationCommand;
use App\Identity\Application\Command\AcceptInvitationHandler;
use App\Identity\Domain\EventStatus;
use App\Identity\Domain\IdentityStatus;
use App\Identity\Domain\InvitationStatus;
use App\Identity\Infrastructure\Doctrine\DoctrineAccessDirectory;
use App\Identity\Infrastructure\Doctrine\DoctrineEventAssignmentWriter;
use App\Identity\Infrastructure\Doctrine\DoctrineInvitationAcceptanceRepository;
use App\Identity\Infrastructure\Security\NativePasswordHasher;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AcceptInvitationHandlerIntegrationTest extends TestCase
{
    private const TOKEN = 'integration-invitation-token';
    private const INVITER_ID = '51000000-0000-4000-8000-000000000001';
    private const USER_ID = '51000000-0000-4000-8000-000000000002';
    private const ORGANIZATION_ID = '52000000-0000-4000-8000-000000000001';
    private const EVENT_ID = '53000000-0000-4000-8000-000000000001';
    private const INVITATION_ID = '54000000-0000-4000-8000-000000000001';
    private const ORGANIZATION_MEMBERSHIP_ID = '55000000-0000-4000-8000-000000000001';
    private const EVENT_MEMBERSHIP_ID = '56000000-0000-4000-8000-000000000001';
    private const AUDIT_ID = '57000000-0000-4000-8000-000000000001';

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

    public function testAcceptsEventInvitationAtomically(): void
    {
        $userId = $this->handler()->handle($this->command());

        self::assertSame(self::USER_ID, $userId);
        self::assertSame(IdentityStatus::ACTIVE->value, $this->connection()->fetchOne(
            'SELECT status FROM users WHERE id = ?',
            [self::USER_ID],
        ));
        self::assertSame('collaborator', $this->connection()->fetchOne(
            'SELECT access_type FROM organization_memberships WHERE id = ?',
            [self::ORGANIZATION_MEMBERSHIP_ID],
        ));
        self::assertSame('SALES_OPERATOR', $this->connection()->fetchOne(
            'SELECT r.code FROM event_membership_roles mr INNER JOIN roles r ON r.id = mr.role_id
             WHERE mr.event_membership_id = ?',
            [self::EVENT_MEMBERSHIP_ID],
        ));
        self::assertSame(InvitationStatus::ACCEPTED->value, $this->connection()->fetchOne(
            'SELECT status FROM access_invitations WHERE id = ?',
            [self::INVITATION_ID],
        ));
        self::assertSame(self::USER_ID, $this->connection()->fetchOne(
            'SELECT accepted_by FROM access_invitations WHERE id = ?',
            [self::INVITATION_ID],
        ));
        self::assertSame('identity.invitation_accepted', $this->connection()->fetchOne(
            'SELECT action FROM audit_logs WHERE id = ?',
            [self::AUDIT_ID],
        ));
    }

    public function testRollsBackEverythingWhenAuditCannotBeWritten(): void
    {
        $this->connection()->insert('audit_logs', [
            'id' => self::AUDIT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'event_id' => self::EVENT_ID,
            'actor_user_id' => self::INVITER_ID,
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
            self::assertFalse($this->connection()->fetchOne('SELECT id FROM users WHERE id = ?', [self::USER_ID]));
            self::assertFalse($this->connection()->fetchOne(
                'SELECT id FROM organization_memberships WHERE id = ?',
                [self::ORGANIZATION_MEMBERSHIP_ID],
            ));
            self::assertFalse($this->connection()->fetchOne(
                'SELECT id FROM event_memberships WHERE id = ?',
                [self::EVENT_MEMBERSHIP_ID],
            ));
            self::assertSame(InvitationStatus::PENDING->value, $this->connection()->fetchOne(
                'SELECT status FROM access_invitations WHERE id = ?',
                [self::INVITATION_ID],
            ));
        }
    }

    public function testAcceptsOrganizationInvitationWithoutGrantingEventAccess(): void
    {
        $this->connection()->update('access_invitations', [
            'event_id' => null,
            'target_access_type' => 'administrator',
            'roles_json' => null,
        ], ['id' => self::INVITATION_ID]);

        $userId = $this->handler()->handle(new AcceptInvitationCommand(
            self::TOKEN,
            'invited@example.test',
            'Invited Administrator',
            self::USER_ID,
            self::ORGANIZATION_MEMBERSHIP_ID,
            null,
            self::AUDIT_ID,
            new \DateTimeImmutable('2026-10-30 12:00:00'),
            password: 'invited-password-1',
        ));

        self::assertSame(self::USER_ID, $userId);
        self::assertSame('administrator', $this->connection()->fetchOne(
            'SELECT access_type FROM organization_memberships WHERE id = ?',
            [self::ORGANIZATION_MEMBERSHIP_ID],
        ));
        self::assertSame('ORG_ADMIN', $this->connection()->fetchOne(
            'SELECT r.code FROM organization_membership_roles mr INNER JOIN roles r ON r.id = mr.role_id
             WHERE mr.organization_membership_id = ?',
            [self::ORGANIZATION_MEMBERSHIP_ID],
        ));
        self::assertFalse($this->connection()->fetchOne(
            'SELECT id FROM event_memberships WHERE user_id = ?',
            [self::USER_ID],
        ));
    }

    public function testCannotConsumeInvitationTwice(): void
    {
        $this->handler()->handle($this->command());

        $this->expectException(\DomainException::class);
        $this->handler()->handle(new AcceptInvitationCommand(
            self::TOKEN,
            'invited@example.test',
            'Invited User',
            '51000000-0000-4000-8000-000000000003',
            '55000000-0000-4000-8000-000000000002',
            '56000000-0000-4000-8000-000000000002',
            '57000000-0000-4000-8000-000000000002',
            new \DateTimeImmutable('2026-10-30 12:05:00'),
            password: 'invited-password-1',
        ));
    }

    public function testStoresOnlyPasswordHashForNewIdentity(): void
    {
        $this->handler()->handle($this->command());

        $hash = $this->connection()->fetchOne('SELECT password_hash FROM users WHERE id = ?', [self::USER_ID]);
        self::assertIsString($hash);
        self::assertNotSame('invited-password-1', $hash);
        self::assertTrue(password_verify('invited-password-1', $hash));
        self::assertNotFalse($this->connection()->fetchOne(
            'SELECT password_updated_at FROM users WHERE id = ?',
            [self::USER_ID],
        ));
    }

    public function testRequiresPasswordWhenInvitationCreatesTheAccount(): void
    {
        try {
            $this->handler()->handle($this->commandWithPassword(null));
            self::fail('Expected missing password to fail.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('password is required', $exception->getMessage());
        }

        self::assertFalse($this->connection()->fetchOne('SELECT id FROM users WHERE id = ?', [self::USER_ID]));
        self::assertSame(InvitationStatus::PENDING->value, $this->connection()->fetchOne(
            'SELECT status FROM access_invitations WHERE id = ?',
            [self::INVITATION_ID],
        ));
    }

    public function testRejectsPasswordOutsidePolicyBeforeConsumingInvitation(): void
    {
        try {
            $this->handler()->handle($this->commandWithPassword('short'));
            self::fail('Expected short password to fail.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('at least', $exception->getMessage());
        }

        self::assertSame(InvitationStatus::PENDING->value, $this->connection()->fetchOne(
            'SELECT status FROM access_invitations WHERE id = ?',
            [self::INVITATION_ID],
        ));
    }

    public function testNeverReplacesPasswordOfExistingIdentity(): void
    {
        $existingId = '51000000-0000-4000-8000-000000000009';
        $originalHash = password_hash('original-password', PASSWORD_BCRYPT);
        $this->connection()->insert('users', [
            'id' => $existingId,
            'email' => 'invited@example.test',
            'email_normalized' => 'invited@example.test',
            'display_name' => 'Existing User',
            'status' => IdentityStatus::ACTIVE->value,
            'password_hash' => $originalHash,
            'created_at' => '2026-10-30 10:00:00',
            'updated_at' => '2026-10-30 10:00:00',
        ]);

        $userId = $this->handler()->handle($this->commandWithPassword('attacker-chosen-password'));

        self::assertSame($existingId, $userId);
        self::assertSame($originalHash, $this->connection()->fetchOne(
            'SELECT password_hash FROM users WHERE id = ?',
            [$existingId],
        ));
    }

    public function testSetsPasswordForExistingIdentityWithoutOne(): void
    {
        $existingId = '51000000-0000-4000-8000-000000000009';
        $this->connection()->insert('users', [
            'id' => $existingId,
            'email' => 'invited@example.test',
            'email_normalized' => 'invited@example.test',
            'display_name' => 'Existing User',
            'status' => IdentityStatus::ACTIVE->value,
            'created_at' => '2026-10-30 10:00:00',
            'updated_at' => '2026-10-30 10:00:00',
        ]);

        $this->handler()->handle($this->commandWithPassword('first-password-1'));

        $hash = $this->connection()->fetchOne('SELECT password_hash FROM users WHERE id = ?', [$existingId]);
        self::assertIsString($hash);
        self::assertTrue(password_verify('first-password-1', $hash));
    }

    private function commandWithPassword(?string $password): AcceptInvitationCommand
    {
        return new AcceptInvitationCommand(
            self::TOKEN,
            'invited@example.test',
            'Invited User',
            self::USER_ID,
            self::ORGANIZATION_MEMBERSHIP_ID,
            self::EVENT_MEMBERSHIP_ID,
            self::AUDIT_ID,
            new \DateTimeImmutable('2026-10-30 12:00:00'),
            password: $password,
        );
    }

    private function handler(): AcceptInvitationHandler
    {
        $connection = $this->connection();

        return new AcceptInvitationHandler(
            new TransactionalExecutor($connection),
            new DoctrineInvitationAcceptanceRepository($connection),
            new DoctrineEventAssignmentWriter($connection),
            new DoctrineAccessDirectory($connection),
            new DoctrineAuditLogWriter($connection),
            new NativePasswordHasher(),
        );
    }

    private function command(): AcceptInvitationCommand
    {
        return new AcceptInvitationCommand(
            self::TOKEN,
            'Invited@Example.Test',
            'Invited User',
            self::USER_ID,
            self::ORGANIZATION_MEMBERSHIP_ID,
            self::EVENT_MEMBERSHIP_ID,
            self::AUDIT_ID,
            new \DateTimeImmutable('2026-10-30 12:00:00'),
            'accept-request',
            'invited-password-1',
        );
    }

    private function insertFixture(): void
    {
        $connection = $this->connection();
        $now = '2026-10-30 11:00:00';
        $connection->insert('users', [
            'id' => self::INVITER_ID,
            'email' => 'inviter@example.test',
            'email_normalized' => 'inviter@example.test',
            'display_name' => 'Inviter',
            'status' => IdentityStatus::ACTIVE->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('organizations', [
            'id' => self::ORGANIZATION_ID,
            'name' => 'Invitation Organization',
            'slug' => 'invitation-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('events', [
            'id' => self::EVENT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'name' => 'Invitation Event',
            'slug' => 'invitation-event',
            'starts_at' => null,
            'ends_at' => null,
            'status' => EventStatus::OPEN->value,
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('access_invitations', [
            'id' => self::INVITATION_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'event_id' => self::EVENT_ID,
            'email_normalized' => 'invited@example.test',
            'target_access_type' => 'operational',
            'roles_json' => json_encode(['SALES_OPERATOR'], JSON_THROW_ON_ERROR),
            'status' => InvitationStatus::PENDING->value,
            'token_hash' => hash('sha256', self::TOKEN),
            'expires_at' => '2026-10-31 12:00:00',
            'invited_by' => self::INVITER_ID,
            'accepted_by' => null,
            'accepted_at' => null,
            'created_at' => $now,
        ]);
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }
}
