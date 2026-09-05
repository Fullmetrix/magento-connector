<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Customer\Model\Customer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CustomerSaveAfter implements ObserverInterface
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
        $customer = $observer->getEvent()->getData('customer');
        if (!$customer instanceof Customer || !$customer->getId()) {
            return;
        }
        try {
            if (!$this->storeScope->includesCustomer($customer)) {
                $this->webhookQueue->enqueueDeleted('customer', (int) $customer->getId());
                return;
            }
            $this->webhookQueue->enqueue(
                'customer',
                (int) $customer->getId(),
                $this->serializer->serializeCustomer($customer),
                'customer.updated'
            );
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
