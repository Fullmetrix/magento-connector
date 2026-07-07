<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Console;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\EntityPaginator;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    public function __construct(
        private readonly Config $config,
        private readonly EntityPaginator $paginator,
        private readonly State $state,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('fullmetrix:status');
        $this->setDescription('Affiche le statut de la connexion Fullmetrix');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Throwable) {
        }

        $output->writeln('Version: ' . Config::VERSION);
        $output->writeln('API base: ' . $this->config->getApiBase());
        $output->writeln('Registered: ' . ($this->config->isRegistered() ? 'yes' : 'no'));
        $output->writeln('Connection code: ' . ($this->config->getConnectionCode() ?: '-'));
        $output->writeln('Webhooks: ' . ($this->config->areWebhooksEnabled() ? 'enabled' : 'disabled'));

        foreach (EntityPaginator::ENTITIES as $entity) {
            $output->writeln(sprintf('%s: %d', $entity, $this->paginator->countByEntity($entity)));
        }

        return Command::SUCCESS;
    }
}
