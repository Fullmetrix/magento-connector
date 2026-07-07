<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;

class StoreSettingsProvider
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getSettings(): array
    {
        $store = $this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore();

        return [
            'currency' => (string) $store->getBaseCurrencyCode(),
            'timezone' => (string) ($this->scopeConfig->getValue('general/locale/timezone') ?: 'UTC'),
            'locale' => (string) ($this->scopeConfig->getValue('general/locale/code') ?: 'en_US'),
            'currencyPosition' => 'left',
            'thousandSeparator' => ',',
            'decimalSeparator' => '.',
            'numDecimals' => 2,
        ];
    }

    public function getSiteUrl(): string
    {
        $store = $this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore();

        return rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB), '/');
    }
}
