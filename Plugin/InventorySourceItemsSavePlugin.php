<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Plugin;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\StoreScope;
use Fullmetrix\Connector\Model\StoreSettingsProvider;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;

class InventorySourceItemsSavePlugin
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
     * @param SourceItemsSaveInterface $subject
     * @param mixed $result
     * @param array $sourceItems
     * @return void
     */
    public function afterExecute(SourceItemsSaveInterface $subject, mixed $result, array $sourceItems): void
    {
        $skus = [];
        foreach ($sourceItems as $sourceItem) {
            if (\is_object($sourceItem) && method_exists($sourceItem, 'getSku')) {
                $sku = trim((string) $sourceItem->getSku());
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
