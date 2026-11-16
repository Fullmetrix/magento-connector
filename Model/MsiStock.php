<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;

class MsiStock
{
    private const MODULES = ['Magento_InventorySalesApi', 'Magento_InventorySales'];
    private const STOCK_RESOLVER = StockResolverInterface::class;
    private const IS_SALABLE = IsProductSalableInterface::class;
    private const SALABLE_QTY = GetProductSalableQtyInterface::class;
    private const WEBSITE_CHANNEL = 'website';

    /**
     * @var bool|null
     */
    private ?bool $available = null;

    /**
     * Inventory services are resolved on use, a store without the Inventory modules never loads them.
     *
     * @param ObjectManagerInterface $objectManager
     * @param ModuleManager $moduleManager
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ModuleManager $moduleManager,
    ) {
    }

    /**
     * Tells whether the Inventory modules are installed and enabled.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        if (null === $this->available) {
            $this->available = $this->modulesEnabled()
                && interface_exists(self::STOCK_RESOLVER)
                && interface_exists(self::IS_SALABLE)
                && interface_exists(self::SALABLE_QTY);
        }

        return $this->available;
    }

    /**
     * Returns the stock assigned to a website.
     *
     * @param string $websiteCode
     * @return int
     */
    public function stockId(string $websiteCode): int
    {
        return (int) $this->service(self::STOCK_RESOLVER)
            ->execute(self::WEBSITE_CHANNEL, $websiteCode)
            ->getStockId();
    }

    /**
     * Tells whether a product can be sold from a stock.
     *
     * @param string $sku
     * @param int $stockId
     * @return bool
     */
    public function isSalable(string $sku, int $stockId): bool
    {
        return (bool) $this->service(self::IS_SALABLE)->execute($sku, $stockId);
    }

    /**
     * Returns the salable quantity of a product on a stock.
     *
     * @param string $sku
     * @param int $stockId
     * @return float
     */
    public function salableQuantity(string $sku, int $stockId): float
    {
        return (float) $this->service(self::SALABLE_QTY)->execute($sku, $stockId);
    }

    /**
     * Returns an Inventory service, a store where Magento cannot build it is treated as a store without Inventory.
     *
     * @param string $type
     * @return object
     * @throws \RuntimeException
     */
    private function service(string $type): object
    {
        try {
            return $this->objectManager->get($type);
        } catch (\Throwable $e) {
            $this->available = false;
            throw new \RuntimeException('Magento Inventory service unavailable: ' . $type, 0, $e);
        }
    }

    /**
     * Tells whether every Inventory module the stock lookups need is enabled.
     *
     * @return bool
     */
    private function modulesEnabled(): bool
    {
        foreach (self::MODULES as $module) {
            if (!$this->moduleManager->isEnabled($module)) {
                return false;
            }
        }

        return true;
    }
}
