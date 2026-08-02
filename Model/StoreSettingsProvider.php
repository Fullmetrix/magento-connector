<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Locale\FormatInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class StoreSettingsProvider
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly FlagManager $flagManager,
        private readonly FormatInterface $localeFormat,
    ) {
    }

    public function getSettings(?int $storeId = null): array
    {
        $store = null !== $storeId ? $this->getStoreById($storeId) : $this->getStore();
        $locale = (string) ($this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $store->getId()) ?: 'en_US');
        $currency = (string) $store->getBaseCurrencyCode();
        $format = $this->localeFormat->getPriceFormat($locale, $currency);
        $pattern = trim((string) ($format['pattern'] ?? ''));

        return [
            'currency' => $currency,
            'timezone' => (string) ($this->scopeConfig->getValue('general/locale/timezone', ScopeInterface::SCOPE_STORE, $store->getId()) ?: 'UTC'),
            'locale' => $locale,
            'currencyPosition' => str_starts_with($pattern, '%s') ? 'right' : 'left',
            'thousandSeparator' => (string) ($format['groupSymbol'] ?? ','),
            'decimalSeparator' => (string) ($format['decimalSymbol'] ?? '.'),
            'numDecimals' => (int) ($format['precision'] ?? 2),
        ];
    }

    public function getSiteUrl(?int $storeId = null): string
    {
        $store = null !== $storeId ? $this->getStoreById($storeId) : $this->getStore();

        return rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB), '/');
    }

    public function getStore(): StoreInterface
    {
        $configuredId = (int) $this->flagManager->getFlagData(Config::FLAG_STORE_ID);
        if ($configuredId > 0) {
            try {
                return $this->storeManager->getStore($configuredId);
            } catch (\Throwable) {
            }
        }

        return $this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore();
    }

    public function getStoreById(int $storeId): StoreInterface
    {
        $store = $this->storeManager->getStore($storeId);
        if ($storeId <= 0 || !(bool) $store->isActive()) {
            throw new \InvalidArgumentException('invalid_store');
        }

        return $store;
    }

    public function getAvailableStores(): array
    {
        $stores = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            if (!(bool) $store->isActive()) {
                continue;
            }
            $website = $this->storeManager->getWebsite((int) $store->getWebsiteId());
            $stores[] = [
                'id' => (int) $store->getId(),
                'label' => (string) $website->getName() . ' — ' . (string) $store->getName(),
            ];
        }

        return $stores;
    }

    public function getStoreId(): int
    {
        return (int) $this->getStore()->getId();
    }

    public function getWebsiteId(): int
    {
        return (int) $this->getStore()->getWebsiteId();
    }

    public function getWebsiteCode(): string
    {
        return (string) $this->storeManager->getWebsite($this->getWebsiteId())->getCode();
    }

    public function getRootCategoryId(): int
    {
        return (int) $this->getStore()->getRootCategoryId();
    }
}
