<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Console;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\ConnectionManager;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ConnectCommand extends Command
{
    /**
     * @param ConnectionManager $connectionManager
     * @param Config $config
     * @param State $state
     */
    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly Config $config,
        private readonly State $state,
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
        $this->setName('fullmetrix:connect');
        $this->setDescription('Connecte la boutique à Fullmetrix avec un code de connexion');
        $this->addArgument('code', InputArgument::REQUIRED, 'Code de connexion FMTX-XXXX-XXXX-XXXX');
        $this->addOption('api-base', null, InputOption::VALUE_OPTIONAL, 'URL API Fullmetrix (dev/test)');
        $this->addOption('store-id', null, InputOption::VALUE_OPTIONAL, 'ID de la vue boutique Magento à connecter');
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

        $apiBase = $input->getOption('api-base');
        if (\is_string($apiBase) && '' !== $apiBase) {
            $this->config->setApiBaseOverride($apiBase);
            $output->writeln('<info>API base: ' . $apiBase . '</info>');
        }

        $storeIdOption = $input->getOption('store-id');
        $storeId = \is_string($storeIdOption) && ctype_digit($storeIdOption) ? (int) $storeIdOption : null;
        $result = $this->connectionManager->connect((string) $input->getArgument('code'), $storeId);
        if (!$result['success']) {
            $output->writeln('<error>Connexion echouee: ' . ($result['error'] ?? 'unknown') . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Boutique connectee a Fullmetrix.</info>');

        return Command::SUCCESS;
    }
}
