<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\DB\Adapter\DeadlockException;

class InventorySaveAfter implements ObserverInterface
{
    /**
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(private readonly WebhookQueue $webhookQueue)
    {
    }

    /**
     * Records the product whose stock changed.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $event = $observer->getEvent();
            $sourceItem = $event->getData('source_item');
            $stockItem = $event->getData('item');
            $sku = null !== $sourceItem && method_exists($sourceItem, 'getSku')
                ? trim((string) $sourceItem->getSku())
                : '';
            if ('' !== $sku) {
                $this->webhookQueue->enqueue('product', 'sku:' . $sku, 'product.updated');
                return;
            }
            $productId = null !== $stockItem && method_exists($stockItem, 'getProductId')
                ? (int) $stockItem->getProductId()
                : 0;
            if ($productId > 0) {
                $this->webhookQueue->enqueue('product', $productId, 'product.updated');
            }
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
