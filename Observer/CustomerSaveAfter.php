<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Customer\Model\Customer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CustomerSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getData('customer');
        if (!$customer instanceof Customer || !$customer->getId()) {
            return;
        }
        try {
            $this->webhookQueue->enqueue('customer', (int) $customer->getId(), $this->serializer->serializeCustomer($customer), 'customer.updated');
        } catch (\Throwable) {
        }
    }
}
