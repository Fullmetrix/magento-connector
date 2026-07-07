<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Console;

use Fullmetrix\Connector\Model\ConnectionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DisconnectCommand extends Command
{
    public function __construct(private readonly ConnectionManager $connectionManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('fullmetrix:disconnect');
        $this->setDescription('Déconnecte la boutique de Fullmetrix');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->connectionManager->disconnect();
        $output->writeln('<info>Boutique deconnectee.</info>');

        return Command::SUCCESS;
    }
}
