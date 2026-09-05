<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Block\Adminhtml;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\StoreSettingsProvider;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Connection extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly StoreSettingsProvider $storeSettings,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function isRegistered(): bool
    {
        return $this->config->isRegistered();
    }

    public function getConnectionCode(): string
    {
        return $this->config->getConnectionCode();
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('fullmetrix/connection/save');
    }

    public function getDisconnectUrl(): string
    {
        return $this->getUrl('fullmetrix/connection/disconnect');
    }

    public function getAppUrl(): string
    {
        return $this->config->getAppOrigin();
    }

    public function getAvailableStores(): array
    {
        return $this->storeSettings->getAvailableStores();
    }

    public function getConnectedStoreLabel(): string
    {
        return (string) $this->storeSettings->getStore()->getName();
    }

    public function getLogoUrl(): string
    {
        return $this->getViewFileUrl('Fullmetrix_Connector::images/logo.png');
    }

    public function isSyncInProgress(): bool
    {
        return $this->config->isSyncInProgress();
    }

    public function hasCompletedSync(): bool
    {
        return $this->config->hasCompletedSync();
    }

    /**
     * @return array<string, int>
     */
    public function getLastSyncEntities(): array
    {
        return $this->config->getLastSyncEntities();
    }
}
