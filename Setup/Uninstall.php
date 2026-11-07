<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Setup;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\LocalScript;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

class Uninstall implements UninstallInterface
{
    /**
     * @param LocalScript $localScript
     * @param Config $config
     */
    public function __construct(
        private readonly LocalScript $localScript,
        private readonly Config $config,
    ) {
    }

    /**
     * Removes the tracker files served from the store and their state.
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        try {
            $this->localScript->removeAll();
        // A leftover directory holds no personal data and must not stop the uninstall.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
        try {
            $this->config->forgetLocalScript();
        // A leftover setting is harmless once the module is gone.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
