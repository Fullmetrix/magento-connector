<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\DB\Adapter\DeadlockException;

class CategorySaveAfter implements ObserverInterface
{
    /**
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(private readonly WebhookQueue $webhookQueue)
    {
    }

    /**
     * Records the category identifier, the cron checks the scope and serializes it.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $category = $observer->getEvent()->getData('category');
            if (!$category instanceof Category || !$category->getId()) {
                return;
            }
            $this->webhookQueue->enqueue('category', (int) $category->getId(), 'category.updated');
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
