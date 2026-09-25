<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\FormatInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class StoreSettingsProvider
{
    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param Config $config
     * @param FormatInterface $localeFormat
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Config $config,
        private readonly FormatInterface $localeFormat,
    ) {
    }

    /**
     * Returns the settings.
     *
     * @param int|null $storeId
     * @return array
     */
    public function getSettings(?int $storeId = null): array
    {
        $store = null !== $storeId ? $this->getStoreById($storeId) : $this->getStore();
        $locale = (string) ($this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        ) ?: 'en_US');
        $currency = (string) $store->getBaseCurrencyCode();
        $format = $this->localeFormat->getPriceFormat($locale, $currency);
        $pattern = trim((string) ($format['pattern'] ?? ''));

        return [
            'currency' => $currency,
            'timezone' => (string) ($this->scopeConfig->getValue(
                'general/locale/timezone',
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            ) ?: 'UTC'),
            'locale' => $locale,
            'currencyPosition' => str_starts_with($pattern, '%s') ? 'right' : 'left',
            'thousandSeparator' => (string) ($format['groupSymbol'] ?? ','),
            'decimalSeparator' => (string) ($format['decimalSymbol'] ?? '.'),
            'numDecimals' => (int) ($format['precision'] ?? 2),
        ];
    }

    /**
     * Returns the site url.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSiteUrl(?int $storeId = null): string
    {
        $store = null !== $storeId ? $this->getStoreById($storeId) : $this->getStore();

        return rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB), '/');
    }

    /**
     * Returns the store.
     *
     * @return StoreInterface
     */
    public function getStore(): StoreInterface
    {
        $configuredId = $this->config->getConfiguredStoreId();
        if ($configuredId > 0) {
            try {
                return $this->storeManager->getStore($configuredId);
            // The failure is optional data, the caller keeps going.
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            } catch (\Throwable) {
            }
        }

        return $this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore();
    }

    /**
     * Returns the store by id.
     *
     * @param int $storeId
     * @return StoreInterface
     */
    public function getStoreById(int $storeId): StoreInterface
    {
        $store = $this->storeManager->getStore($storeId);
        if ($storeId <= 0 || !(bool) $store->isActive()) {
            throw new \InvalidArgumentException('invalid_store');
        }

        return $store;
    }

    /**
     * Returns the available stores.
     *
     * @return array
     */
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
                'label' => (string) $website->getName() . ' / ' . (string) $store->getName(),
            ];
        }

        return $stores;
    }

    /**
     * Returns the store id.
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return (int) $this->getStore()->getId();
    }

    /**
     * Returns the website id.
     *
     * @return int
     */
    public function getWebsiteId(): int
    {
        return (int) $this->getStore()->getWebsiteId();
    }

    /**
     * Returns the website code.
     *
     * @return string
     */
    public function getWebsiteCode(): string
    {
        return (string) $this->storeManager->getWebsite($this->getWebsiteId())->getCode();
    }

    /**
     * Returns the root category id.
     *
     * @return int
     */
    public function getRootCategoryId(): int
    {
        return (int) $this->getStore()->getRootCategoryId();
    }
}
