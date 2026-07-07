<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\Subscriber;

class SubscriberSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
        private readonly CustomerFactory $customerFactory,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $subscriber = $observer->getEvent()->getData('subscriber');
        if (!$subscriber instanceof Subscriber) {
            return;
        }
        $customerId = (int) $subscriber->getCustomerId();
        if ($customerId <= 0) {
            return;
        }
        try {
            $customer = $this->customerFactory->create()->load($customerId);
            if (!$customer->getId()) {
                return;
            }
            $this->webhookQueue->enqueue('customer', $customerId, $this->serializer->serializeCustomer($customer), 'customer.updated');
        } catch (\Throwable) {
        }
    }
}
