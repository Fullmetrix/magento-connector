<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CategorySaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $category = $observer->getEvent()->getData('category');
        if (!$category instanceof Category || !$category->getId() || (int) $category->getLevel() < 2) {
            return;
        }
        try {
            $this->webhookQueue->enqueue('category', (int) $category->getId(), $this->serializer->serializeCategory($category), 'category.updated');
        } catch (\Throwable) {
        }
    }
}
