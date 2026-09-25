<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Customer\Model\Customer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\DB\Adapter\DeadlockException;

class CustomerSaveAfter implements ObserverInterface
{
    /**
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(private readonly WebhookQueue $webhookQueue)
    {
    }

    /**
     * Records the customer identifier, the cron checks the scope and serializes it.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $customer = $observer->getEvent()->getData('customer');
            if (!$customer instanceof Customer || !$customer->getId()) {
                return;
            }
            $this->webhookQueue->enqueue('customer', (int) $customer->getId(), 'customer.updated');
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
