<?php

declare(strict_types=1);

namespace App\Tests\Identity\Application\Command;

use App\Audit\Infrastructure\Doctrine\DoctrineAuditLogWriter;
use App\Identity\Application\Access\AccessScopePolicy;
use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\RolePermissionMap;
use App\Identity\Application\Command\AccessMutationFailed;
use App\Identity\Application\Command\CreateInvitationCommand;
use App\Identity\Application\Command\CreateInvitationHandler;
use App\Identity\Application\Invitation\InvitationTokenGenerator;
use App\Identity\Application\Invitation\InvitationDeliveryProcessor;
use App\Identity\Application\Invitation\InvitationEmailSender;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\EventStatus;
use App\Identity\Domain\IdentityStatus;
use App\Identity\Domain\InvitationStatus;
use App\Identity\Domain\MembershipStatus;
use App\Identity\Domain\OrganizationAccessType;
use App\Identity\Infrastructure\Doctrine\DoctrineAccessDirectory;
use App\Identity\Infrastructure\Doctrine\DoctrineInvitationWriter;
use App\Identity\Infrastructure\Security\SecureInvitationTokenGenerator;
use App\Identity\Infrastructure\Doctrine\DoctrineInvitationDeliveryOutbox;
use App\Identity\Infrastructure\Doctrine\DoctrineInvitationDeliveryQueue;
use App\Shared\Application\Identifier\IdentifierGenerator;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class CreateInvitationHandlerIntegrationTest extends TestCase
{
    private const TOKEN = 'deterministic-test-token';
    private const ACTOR_ID = '71000000-0000-4000-8000-000000000001';
    private const ORGANIZATION_ID = '72000000-0000-4000-8000-000000000001';
    private const EVENT_ID = '73000000-0000-4000-8000-000000000001';
    private const INVITATION_ID = '74000000-0000-4000-8000-000000000001';
    private const DELIVERY_ID = '74500000-0000-4000-8000-000000000001';
    private const AUDIT_ID = '75000000-0000-4000-8000-000000000001';

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

    public function testCreatesEventInvitationAndStoresOnlyTokenHash(): void
    {
        $result = $this->handler()->handle($this->eventCommand());

        self::assertSame(self::INVITATION_ID, $result->invitationId);
        self::assertSame(self::DELIVERY_ID, $result->deliveryId);
        $row = $this->connection()->fetchAssociative(
            'SELECT email_normalized, target_access_type, roles_json, status, token_hash
             FROM access_invitations WHERE id = ?',
            [self::INVITATION_ID],
        );
        self::assertIsArray($row);
        self::assertSame('invited@example.test', $row['email_normalized']);
        self::assertSame(EventAccessType::OPERATIONAL->value, $row['target_access_type']);
        self::assertSame(['SALES_OPERATOR'], json_decode((string) $row['roles_json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(InvitationStatus::PENDING->value, $row['status']);
        self::assertSame(hash('sha256', self::TOKEN), $row['token_hash']);
        self::assertNotSame(self::TOKEN, $row['token_hash']);
        $delivery = $this->connection()->fetchAssociative(
            'SELECT aggregate_id, recipient, payload_json, token_key_version, status
             FROM notification_deliveries WHERE id = ?',
            [self::DELIVERY_ID],
        );
        self::assertIsArray($delivery);
        self::assertSame(self::INVITATION_ID, $delivery['aggregate_id']);
        self::assertSame('invited@example.test', $delivery['recipient']);
        self::assertSame(['invitation_id' => self::INVITATION_ID], json_decode((string) $delivery['payload_json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::TOKEN, (string) $delivery['payload_json']);
        self::assertSame('test-v1', $delivery['token_key_version']);
        self::assertSame('pending', $delivery['status']);
        self::assertSame('identity.invitation_created', $this->connection()->fetchOne(
            'SELECT action FROM audit_logs WHERE id = ?',
            [self::AUDIT_ID],
        ));
    }

    public function testRejectsDuplicatePendingInvitation(): void
    {
        $this->handler()->handle($this->eventCommand());

        $this->expectException(AccessMutationFailed::class);
        $this->expectExceptionMessage('pending invitation already exists');
        $this->handler()->handle(new CreateInvitationCommand(
            self::ACTOR_ID,
            self::ORGANIZATION_ID,
            self::EVENT_ID,
            'invited@example.test',
            EventAccessType::OPERATIONAL,
            [EventRole::SALES_OPERATOR],
            '74000000-0000-4000-8000-000000000002',
            '74500000-0000-4000-8000-000000000002',
            '75000000-0000-4000-8000-000000000002',
            new \DateTimeImmutable('2026-11-01 12:00:00'),
            new \DateTimeImmutable('2026-10-30 12:05:00'),
        ));
    }

    public function testRollsBackInvitationWhenAuditCannotBeWritten(): void
    {
        $this->insertConflictingAudit();

        try {
            $this->handler()->handle($this->eventCommand());
            self::fail('Expected duplicate audit identifier to fail.');
        } catch (\Throwable) {
            self::assertFalse($this->connection()->fetchOne(
                'SELECT id FROM access_invitations WHERE id = ?',
                [self::INVITATION_ID],
            ));
            self::assertFalse($this->connection()->fetchOne(
                'SELECT id FROM notification_deliveries WHERE id = ?',
                [self::DELIVERY_ID],
            ));
        }
    }

    public function testCreatesOrganizationInvitationWithoutEventRoles(): void
    {
        $result = $this->handler()->handle(new CreateInvitationCommand(
            self::ACTOR_ID,
            self::ORGANIZATION_ID,
            null,
            'administrator@example.test',
            OrganizationAccessType::ADMINISTRATOR,
            [],
            self::INVITATION_ID,
            self::DELIVERY_ID,
            self::AUDIT_ID,
            new \DateTimeImmutable('2026-11-01 12:00:00'),
            new \DateTimeImmutable('2026-10-30 12:00:00'),
        ));

        self::assertSame(self::DELIVERY_ID, $result->deliveryId);
        $row = $this->connection()->fetchAssociative(
            'SELECT event_id, target_access_type, roles_json FROM access_invitations WHERE id = ?',
            [self::INVITATION_ID],
        );
        self::assertIsArray($row);
        self::assertNull($row['event_id']);
        self::assertSame(OrganizationAccessType::ADMINISTRATOR->value, $row['target_access_type']);
        self::assertNull($row['roles_json']);
    }

    public function testWorkerDerivesTokenOutsideBusinessTransactionAndMarksDeliverySent(): void
    {
        $this->handler()->handle($this->eventCommand());
        $sender = new class implements InvitationEmailSender {
            /** @var array{recipient: string, invitation_id: string, token: string}|null */
            public ?array $message = null;

            public function send(string $recipient, string $invitationId, string $token): string
            {
                $this->message = compact('recipient', 'invitationId', 'token');

                return 'provider-message-1';
            }
        };

        $processed = $this->processor($sender)->processOne(new \DateTimeImmutable('2026-10-30 12:01:00'));

        self::assertTrue($processed);
        self::assertSame([
            'recipient' => 'invited@example.test',
            'invitationId' => self::INVITATION_ID,
            'token' => self::TOKEN,
        ], $sender->message);
        $row = $this->connection()->fetchAssociative(
            'SELECT status, attempts, provider_reference, sent_at, lock_token, locked_at
             FROM notification_deliveries WHERE id = ?',
            [self::DELIVERY_ID],
        );
        self::assertIsArray($row);
        self::assertSame('sent', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('provider-message-1', $row['provider_reference']);
        self::assertNotNull($row['sent_at']);
        self::assertNull($row['lock_token']);
        self::assertNull($row['locked_at']);
    }

    public function testWorkerReleasesDeliveryForRetryWhenProviderFails(): void
    {
        $this->handler()->handle($this->eventCommand());
        $sender = new class implements InvitationEmailSender {
            public function send(string $recipient, string $invitationId, string $token): string
            {
                throw new \RuntimeException('temporary provider failure');
            }
        };

        self::assertTrue($this->processor($sender)->processOne(new \DateTimeImmutable('2026-10-30 12:01:00')));

        $row = $this->connection()->fetchAssociative(
            'SELECT status, attempts, next_attempt_at, last_error, lock_token
             FROM notification_deliveries WHERE id = ?',
            [self::DELIVERY_ID],
        );
        self::assertIsArray($row);
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('2026-10-30 12:03:00', $row['next_attempt_at']);
        self::assertSame('temporary provider failure', $row['last_error']);
        self::assertNull($row['lock_token']);
    }

    public function testWorkerUsesLegacySecretForDeliveryCreatedBeforeRotation(): void
    {
        $oldSecret = str_repeat('o', 32);
        $oldTokens = new SecureInvitationTokenGenerator($oldSecret, 'v1');
        $this->handler($oldTokens)->handle($this->eventCommand());
        $sender = new class implements InvitationEmailSender {
            public ?string $token = null;

            public function send(string $recipient, string $invitationId, string $token): string
            {
                $this->token = $token;

                return 'provider-message-rotated';
            }
        };
        $rotatedTokens = new SecureInvitationTokenGenerator(
            str_repeat('n', 32),
            'v2',
            ['v1' => $oldSecret],
        );

        self::assertTrue($this->processor($sender, $rotatedTokens)->processOne(
            new \DateTimeImmutable('2026-10-30 12:01:00'),
        ));
        self::assertSame($oldTokens->generate(self::INVITATION_ID), $sender->token);
        self::assertSame('sent', $this->connection()->fetchOne(
            'SELECT status FROM notification_deliveries WHERE id = ?',
            [self::DELIVERY_ID],
        ));
    }

    private function handler(?InvitationTokenGenerator $tokens = null): CreateInvitationHandler
    {
        $connection = $this->connection();
        $directory = new DoctrineAccessDirectory($connection);
        $tokens ??= $this->tokenGenerator();

        return new CreateInvitationHandler(
            new TransactionalExecutor($connection),
            new DoctrineInvitationWriter($connection),
            $tokens,
            new DoctrineInvitationDeliveryOutbox($connection),
            $directory,
            new AuthorizationPolicy(new AccessScopePolicy($directory), $directory, new RolePermissionMap()),
            new DoctrineAuditLogWriter($connection),
        );
    }

    private function tokenGenerator(): InvitationTokenGenerator
    {
        return new class(self::TOKEN) implements InvitationTokenGenerator {
            public function __construct(private readonly string $token)
            {
            }

            public function generate(string $invitationId, ?string $keyVersion = null): string
            {
                return $this->token;
            }

            public function keyVersion(): string
            {
                return 'test-v1';
            }
        };
    }

    private function processor(
        InvitationEmailSender $sender,
        ?InvitationTokenGenerator $tokens = null,
    ): InvitationDeliveryProcessor
    {
        $connection = $this->connection();
        $identifiers = new class implements IdentifierGenerator {
            public function generate(): string
            {
                return '75500000-0000-4000-8000-000000000001';
            }
        };

        return new InvitationDeliveryProcessor(
            new DoctrineInvitationDeliveryQueue($connection, new TransactionalExecutor($connection)),
            $sender,
            $tokens ?? $this->tokenGenerator(),
            $identifiers,
        );
    }

    private function eventCommand(): CreateInvitationCommand
    {
        return new CreateInvitationCommand(
            self::ACTOR_ID,
            self::ORGANIZATION_ID,
            self::EVENT_ID,
            'Invited@Example.Test',
            EventAccessType::OPERATIONAL,
            [EventRole::SALES_OPERATOR],
            self::INVITATION_ID,
            self::DELIVERY_ID,
            self::AUDIT_ID,
            new \DateTimeImmutable('2026-11-01 12:00:00'),
            new \DateTimeImmutable('2026-10-30 12:00:00'),
            'create-invitation-request',
        );
    }

    private function insertFixture(): void
    {
        $connection = $this->connection();
        $now = '2026-10-30 11:00:00';
        $connection->insert('users', [
            'id' => self::ACTOR_ID,
            'email' => 'invitation-owner@example.test',
            'email_normalized' => 'invitation-owner@example.test',
            'display_name' => 'Invitation Owner',
            'status' => IdentityStatus::ACTIVE->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('organizations', [
            'id' => self::ORGANIZATION_ID,
            'name' => 'Invitation Creation Organization',
            'slug' => 'invitation-creation-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('organization_memberships', [
            'id' => '76000000-0000-4000-8000-000000000001',
            'organization_id' => self::ORGANIZATION_ID,
            'user_id' => self::ACTOR_ID,
            'access_type' => OrganizationAccessType::OWNER->value,
            'status' => MembershipStatus::ACTIVE->value,
            'valid_from' => null,
            'valid_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('events', [
            'id' => self::EVENT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'name' => 'Invitation Creation Event',
            'slug' => 'invitation-creation-event',
            'starts_at' => null,
            'ends_at' => null,
            'status' => EventStatus::OPEN->value,
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertConflictingAudit(): void
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
            'created_at' => '2026-10-30 11:00:00',
        ]);
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }
}
