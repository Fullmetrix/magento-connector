<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;

class StockProvider
{
    /**
     * @param StockRegistryInterface $stockRegistry
     * @param StockResolverInterface $stockResolver
     * @param GetProductSalableQtyInterface $getProductSalableQty
     * @param IsProductSalableInterface $isProductSalable
     * @param StoreSettingsProvider $storeSettings
     */
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StockResolverInterface $stockResolver,
        private readonly GetProductSalableQtyInterface $getProductSalableQty,
        private readonly IsProductSalableInterface $isProductSalable,
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
        if ('' !== $sku) {
            try {
                $stock = $this->stockResolver->execute(
                    SalesChannelInterface::TYPE_WEBSITE,
                    $this->storeSettings->getWebsiteCode()
                );
                $stockId = (int) $stock->getStockId();
                $quantity = (float) $this->getProductSalableQty->execute($sku, $stockId);

                return [
                    'status' => $this->isProductSalable->execute($sku, $stockId) ? 'instock' : 'outofstock',
                    'quantity' => $quantity,
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
}
