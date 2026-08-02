<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\StoreScope;
use Fullmetrix\Connector\Model\StoreSettingsProvider;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class InventorySaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly EntitySerializer $serializer,
        private readonly WebhookQueue $webhookQueue,
        private readonly StoreScope $storeScope,
        private readonly StoreSettingsProvider $storeSettings,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $sourceItem = $event->getData('source_item');
        $stockItem = $event->getData('item');
        $sku = null !== $sourceItem && method_exists($sourceItem, 'getSku')
            ? (string) $sourceItem->getSku()
            : '';

        try {
            $product = '' !== $sku
                ? $this->productRepository->get($sku, false, $this->storeSettings->getStoreId())
                : $this->productRepository->getById(
                    (int) (null !== $stockItem && method_exists($stockItem, 'getProductId') ? $stockItem->getProductId() : 0),
                    false,
                    $this->storeSettings->getStoreId()
                );
            if (!$product instanceof Product || !$product->getId() || !$this->storeScope->includesProduct($product)) {
                return;
            }
            $this->webhookQueue->enqueue(
                'product',
                (int) $product->getId(),
                $this->serializer->serializeProduct($product),
                'product.updated'
            );
        } catch (\Throwable) {
        }
    }
}
