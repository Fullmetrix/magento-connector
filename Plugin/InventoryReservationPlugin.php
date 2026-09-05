<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Plugin;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\StoreScope;
use Fullmetrix\Connector\Model\StoreSettingsProvider;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\InventorySales\Model\PlaceReservationsForSalesEvent;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;

class InventoryReservationPlugin
{
    /**
     * @param ProductRepositoryInterface $productRepository
     * @param EntitySerializer $serializer
     * @param WebhookQueue $webhookQueue
     * @param StoreScope $storeScope
     * @param StoreSettingsProvider $storeSettings
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly EntitySerializer $serializer,
        private readonly WebhookQueue $webhookQueue,
        private readonly StoreScope $storeScope,
        private readonly StoreSettingsProvider $storeSettings,
    ) {
    }

    /**
     * After execute.
     *
     * @param PlaceReservationsForSalesEvent $subject
     * @param mixed $result
     * @param array $items
     * @param SalesChannelInterface $salesChannel
     * @return void
     */
    public function afterExecute(
        PlaceReservationsForSalesEvent $subject,
        mixed $result,
        array $items,
        SalesChannelInterface $salesChannel,
    ): void {
        if ((string) $salesChannel->getCode() !== $this->storeSettings->getWebsiteCode()) {
            return;
        }
        $skus = [];
        foreach ($items as $item) {
            if (\is_object($item) && method_exists($item, 'getSku')) {
                $sku = trim((string) $item->getSku());
                if ('' !== $sku) {
                    $skus[$sku] = true;
                }
            }
        }
        foreach (array_keys($skus) as $sku) {
            try {
                $product = $this->productRepository->get(
                    $sku,
                    false,
                    $this->storeSettings->getStoreId(),
                    true
                );
                if (!$product instanceof Product || !$this->storeScope->includesProduct($product)) {
                    continue;
                }
                $this->webhookQueue->enqueue(
                    'product',
                    (int) $product->getId(),
                    $this->serializer->serializeProduct($product),
                    'product.updated'
                );
            // The failure is optional data, the caller keeps going.
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            } catch (\Throwable) {
            }
        }
    }
}
