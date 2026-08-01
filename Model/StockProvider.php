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
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StockResolverInterface $stockResolver,
        private readonly GetProductSalableQtyInterface $getProductSalableQty,
        private readonly IsProductSalableInterface $isProductSalable,
        private readonly StoreSettingsProvider $storeSettings,
    ) {
    }

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
