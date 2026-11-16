<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;

class StockProvider
{
    /**
     * @param StockRegistryInterface $stockRegistry
     * @param MsiStock $msiStock
     * @param StoreSettingsProvider $storeSettings
     */
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly MsiStock $msiStock,
        private readonly StoreSettingsProvider $storeSettings,
    ) {
    }

    /**
     * Returns the.
     *
     * @param Product $product
     * @return array
     */
    public function get(Product $product): array
    {
        $stockItem = null;
        try {
            $stockItem = $this->stockRegistry->getStockItem(
                (int) $product->getId(),
                $this->storeSettings->getWebsiteId()
            );
            if (!$stockItem->getManageStock()) {
                return [
                    'status' => $stockItem->getIsInStock() ? 'instock' : 'outofstock',
                    'quantity' => null,
                    'manage' => false,
                ];
            }
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }

        $sku = (string) $product->getSku();
        if ('' !== $sku && $this->msiStock->isAvailable()) {
            try {
                $stockId = $this->msiStock->stockId($this->storeSettings->getWebsiteCode());
                $status = $this->msiStock->isSalable($sku, $stockId) ? 'instock' : 'outofstock';

                return [
                    'status' => $status,
                    'quantity' => $this->salableQuantity($sku, $stockId),
                    'manage' => true,
                ];
            // The failure is optional data, the caller keeps going.
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            } catch (\Throwable) {
            }
        }

        try {
            $stockItem ??= $this->stockRegistry->getStockItem(
                (int) $product->getId(),
                $this->storeSettings->getWebsiteId()
            );

            return [
                'status' => $stockItem->getIsInStock() ? 'instock' : 'outofstock',
                'quantity' => (float) $stockItem->getQty(),
                'manage' => (bool) $stockItem->getManageStock(),
            ];
        } catch (\Throwable) {
            return ['status' => 'outofstock', 'quantity' => null, 'manage' => false];
        }
    }

    /**
     * Returns the salable quantity of a product on a stock, or null for a composite product.
     *
     * @param string $sku
     * @param int $stockId
     * @return float|null
     */
    private function salableQuantity(string $sku, int $stockId): ?float
    {
        try {
            return $this->msiStock->salableQuantity($sku, $stockId);
        // A composite product has no salable quantity of its own.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }

        return null;
    }
}
