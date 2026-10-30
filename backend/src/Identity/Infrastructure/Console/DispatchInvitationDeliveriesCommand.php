<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\Invitation\InvitationDeliveryProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:invitations:dispatch',
    description: 'Procesa entregas pendientes de invitaciones de acceso.',
)]
final class DispatchInvitationDeliveriesCommand extends Command
{
    public function __construct(private readonly InvitationDeliveryProcessor $processor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Cantidad maxima de entregas a procesar en esta ejecucion.',
            '50',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);
        if (false === $limit) {
            $output->writeln('<error>--limit debe ser un entero entre 1 y 1000.</error>');

            return Command::INVALID;
        }

        $processed = 0;
        while ($processed < $limit) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (!$this->processor->processOne($now)) {
                break;
            }
            ++$processed;
        }

        $output->writeln(sprintf('Entregas procesadas: %d', $processed));

        return Command::SUCCESS;
    }
}
