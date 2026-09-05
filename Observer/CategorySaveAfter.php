<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CategorySaveAfter implements ObserverInterface
{
    /**
     * @param WebhookQueue $webhookQueue
     * @param EntitySerializer $serializer
     * @param StoreScope $storeScope
     */
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
        private readonly StoreScope $storeScope,
    ) {
    }

    /**
     * Runs the controller action.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $category = $observer->getEvent()->getData('category');
        if (!$category instanceof Category || !$category->getId()) {
            return;
        }
        try {
            if (!$this->storeScope->includesCategory($category)) {
                $this->webhookQueue->enqueueDeleted('category', (int) $category->getId());
                return;
            }
            $this->webhookQueue->enqueue(
                'category',
                (int) $category->getId(),
                $this->serializer->serializeCategory($category),
                'category.updated'
            );
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
