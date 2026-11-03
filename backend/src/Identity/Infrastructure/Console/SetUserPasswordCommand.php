<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\Credential\PasswordHasher;
use App\Identity\Application\Credential\PasswordPolicy;
use App\Identity\Application\Credential\UserCredentialRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Asigna o reemplaza la contraseña de un usuario existente (usuarios de desarrollo o soporte).
 * La contraseña nunca se recibe como argumento para que no quede en el historial de la terminal:
 * se pide de forma oculta o se lee de una variable de entorno (--from-env).
 */
#[AsCommand(
    name: 'app:users:set-password',
    description: 'Asigna la contraseña de un usuario existente.',
)]
final class SetUserPasswordCommand extends Command
{
    public function __construct(
        private readonly UserCredentialRepository $credentials,
        private readonly PasswordHasher $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Correo del usuario.')
            ->addOption(
                'from-env',
                null,
                InputOption::VALUE_REQUIRED,
                'Nombre de la variable de entorno que contiene la contraseña (para scripts).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = strtolower(trim((string) $input->getArgument('email')));
        $user = $this->credentials->findByEmail($email);
        if (null === $user) {
            $output->writeln('<error>No existe un usuario con ese correo.</error>');

            return Command::FAILURE;
        }

        $password = $this->readPassword($input, $output);
        if (null === $password) {
            return Command::INVALID;
        }
        $violation = PasswordPolicy::violation($password);
        if (null !== $violation) {
            $output->writeln('<error>'.$violation.'</error>');

            return Command::INVALID;
        }

        $this->credentials->updatePasswordHash(
            $user['id'],
            $this->hasher->hash($password),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        $output->writeln(sprintf('<info>Contraseña actualizada para %s.</info>', $user['email']));

        return Command::SUCCESS;
    }

    private function readPassword(InputInterface $input, OutputInterface $output): ?string
    {
        $variable = $input->getOption('from-env');
        if (is_string($variable) && '' !== $variable) {
            $value = getenv($variable);
            if (!is_string($value) || '' === $value) {
                $output->writeln(sprintf('<error>La variable %s está vacía o no existe.</error>', $variable));

                return null;
            }

            return $value;
        }

        if (!$input->isInteractive()) {
            $output->writeln('<error>Sin terminal interactiva use --from-env=NOMBRE_VARIABLE.</error>');

            return null;
        }

        $helper = $this->getHelper('question');
        $first = $helper->ask($input, $output, (new Question('Contraseña: '))->setHidden(true)->setTrimmable(false));
        $second = $helper->ask($input, $output, (new Question('Repita la contraseña: '))->setHidden(true)->setTrimmable(false));
        // Sin recorte automático (los espacios son parte de la contraseña); solo se quita el salto de línea.
        $first = is_string($first) ? rtrim($first, "\r\n") : null;
        $second = is_string($second) ? rtrim($second, "\r\n") : null;
        if (null === $first || $first !== $second) {
            $output->writeln('<error>Las contraseñas no coinciden.</error>');

            return null;
        }

        return $first;
    }
}
