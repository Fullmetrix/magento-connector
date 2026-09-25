<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Block\Adminhtml;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\StoreSettingsProvider;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Backs the Fullmetrix connection screen in the Magento admin.
 */
class Connection extends Template
{
    /**
     * @param Context $context
     * @param Config $config
     * @param StoreSettingsProvider $storeSettings
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly StoreSettingsProvider $storeSettings,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Tells whether the store is already paired with a Fullmetrix account.
     *
     * @return bool
     */
    public function isRegistered(): bool
    {
        return $this->config->isRegistered();
    }

    /**
     * Returns the connection code entered by the merchant.
     *
     * @return string
     */
    public function getConnectionCode(): string
    {
        return $this->config->getConnectionCode();
    }

    /**
     * Returns the URL the connection form posts to.
     *
     * @return string
     */
    public function getSaveUrl(): string
    {
        return $this->getUrl('fullmetrix/connection/save');
    }

    /**
     * Returns the URL the disconnect form posts to.
     *
     * @return string
     */
    public function getDisconnectUrl(): string
    {
        return $this->getUrl('fullmetrix/connection/disconnect');
    }

    /**
     * Returns the origin of the Fullmetrix application.
     *
     * @return string
     */
    public function getAppUrl(): string
    {
        return $this->config->getAppOrigin();
    }

    /**
     * Returns the store views the merchant can connect.
     *
     * @return array
     */
    public function getAvailableStores(): array
    {
        return $this->storeSettings->getAvailableStores();
    }

    /**
     * Returns the name of the store view currently connected.
     *
     * @return string
     */
    public function getConnectedStoreLabel(): string
    {
        return (string) $this->storeSettings->getStore()->getName();
    }

    /**
     * Returns the URL of the Fullmetrix logo shipped with the module.
     *
     * @return string
     */
    public function getLogoUrl(): string
    {
        return $this->getViewFileUrl('Fullmetrix_Connector::images/logo.png');
    }

    /**
     * Tells whether an export is currently running.
     *
     * @return bool
     */
    public function isSyncInProgress(): bool
    {
        return $this->config->isSyncInProgress();
    }

    /**
     * Tells whether at least one export has completed.
     *
     * @return bool
     */
    public function hasCompletedSync(): bool
    {
        return $this->config->hasCompletedSync();
    }

    /**
     * Returns the row count exported per entity during the last sync.
     *
     * @return array<string, int>
     */
    public function getLastSyncEntities(): array
    {
        return $this->config->getLastSyncEntities();
    }

    /**
     * Formats a row count for display.
     *
     * @param int $count
     * @return string
     */
    public function formatCount(int $count): string
    {
        return number_format((float) $count, 0, ',', ' ');
    }
}
