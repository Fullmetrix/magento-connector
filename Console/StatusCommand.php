<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Console;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\EntityPaginator;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    /**
     * @param Config $config
     * @param EntityPaginator $paginator
     * @param State $state
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(
        private readonly Config $config,
        private readonly EntityPaginator $paginator,
        private readonly State $state,
        private readonly WebhookQueue $webhookQueue,
    ) {
        parent::__construct();
    }

    /**
     * Configure.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('fullmetrix:status');
        $this->setDescription('Affiche le statut de la connexion Fullmetrix');
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
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }

        $output->writeln('Version: ' . Config::VERSION);
        $output->writeln('API base: ' . $this->config->getApiBase());
        $output->writeln('Registered: ' . ($this->config->isRegistered() ? 'yes' : 'no'));
        $output->writeln('Connection code: ' . ($this->config->getConnectionCode() ?: '-'));
        $output->writeln('Webhooks: ' . ($this->config->areWebhooksEnabled() ? 'enabled' : 'disabled'));
        $queue = $this->webhookQueue->stats();
        $output->writeln(sprintf(
            'Queue: %d pending, oldest unsent waits for %d s (the Magento cron sends it every minute)',
            $queue['pending'],
            $queue['waiting_seconds']
        ));

        foreach (EntityPaginator::ENTITIES as $entity) {
            $output->writeln(sprintf('%s: %d', $entity, $this->paginator->countByEntity($entity)));
        }

        return Command::SUCCESS;
    }
}
