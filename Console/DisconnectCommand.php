<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Console;

use Fullmetrix\Connector\Model\ConnectionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DisconnectCommand extends Command
{
    /**
     * @param ConnectionManager $connectionManager
     */
    public function __construct(private readonly ConnectionManager $connectionManager)
    {
        parent::__construct();
    }

    /**
     * Configure.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('fullmetrix:disconnect');
        $this->setDescription('Déconnecte la boutique de Fullmetrix');
    }

    /**
     * Runs the controller action.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->connectionManager->disconnect();
        $output->writeln('<info>Boutique deconnectee.</info>');

        return Command::SUCCESS;
    }
}
