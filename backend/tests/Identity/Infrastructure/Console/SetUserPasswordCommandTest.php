<?php

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Console;

use App\Identity\Application\Credential\UserCredentialRepository;
use App\Identity\Infrastructure\Console\SetUserPasswordCommand;
use App\Identity\Infrastructure\Security\NativePasswordHasher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SetUserPasswordCommandTest extends TestCase
{
    private const ENV = 'KERMESFERA_TEST_NEW_PASSWORD';

    private InMemoryCredentials $credentials;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->credentials = new InMemoryCredentials();
        $application = new Application();
        $application->add(new SetUserPasswordCommand($this->credentials, new NativePasswordHasher()));
        $this->tester = new CommandTester($application->find('app:users:set-password'));
    }

    protected function tearDown(): void
    {
        putenv(self::ENV);
    }

    public function testSetsPasswordFromHiddenPrompt(): void
    {
        $this->tester->setInputs(['nueva-clave-1', 'nueva-clave-1']);

        $status = $this->tester->execute(['email' => ' Dev@Example.Test ']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertNotNull($this->credentials->hash);
        self::assertTrue(password_verify('nueva-clave-1', $this->credentials->hash));
        self::assertStringNotContainsString('nueva-clave-1', $this->tester->getDisplay());
    }

    public function testRejectsMismatchedConfirmation(): void
    {
        $this->tester->setInputs(['nueva-clave-1', 'otra-clave-2']);

        self::assertSame(Command::INVALID, $this->tester->execute(['email' => 'dev@example.test']));
        self::assertNull($this->credentials->hash);
    }

    public function testReadsPasswordFromEnvironmentForScripts(): void
    {
        putenv(self::ENV.'=desde-variable-1');

        $status = $this->tester->execute(
            ['email' => 'dev@example.test', '--from-env' => self::ENV],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $status);
        self::assertTrue(password_verify('desde-variable-1', (string) $this->credentials->hash));
    }

    public function testRejectsPasswordOutsidePolicy(): void
    {
        putenv(self::ENV.'=corta');

        $status = $this->tester->execute(
            ['email' => 'dev@example.test', '--from-env' => self::ENV],
            ['interactive' => false],
        );

        self::assertSame(Command::INVALID, $status);
        self::assertNull($this->credentials->hash);
    }

    public function testFailsForUnknownUser(): void
    {
        $status = $this->tester->execute(['email' => 'nadie@example.test'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
    }
}

final class InMemoryCredentials implements UserCredentialRepository
{
    public ?string $hash = null;

    public function findByEmail(string $emailNormalized): ?array
    {
        if ('dev@example.test' !== $emailNormalized) {
            return null;
        }

        return [
            'id' => 'c1000000-0000-4000-8000-000000000001',
            'email' => 'dev@example.test',
            'display_name' => 'Dev',
            'status' => 'active',
            'password_hash' => $this->hash,
        ];
    }

    public function updatePasswordHash(string $userId, string $passwordHash, \DateTimeImmutable $at): void
    {
        $this->hash = $passwordHash;
    }
}
