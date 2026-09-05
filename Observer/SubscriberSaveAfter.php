<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\Subscriber;

class SubscriberSaveAfter implements ObserverInterface
{
    /**
     * @param WebhookQueue $webhookQueue
     * @param EntitySerializer $serializer
     * @param CustomerFactory $customerFactory
     * @param StoreScope $storeScope
     */
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
        private readonly CustomerFactory $customerFactory,
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
        $subscriber = $observer->getEvent()->getData('subscriber');
        if (!$subscriber instanceof Subscriber) {
            return;
        }
        if (!$this->storeScope->includesWebsiteStore((int) $subscriber->getStoreId())) {
            return;
        }
        $email = (string) $subscriber->getSubscriberEmail();
        $customerId = (int) $subscriber->getCustomerId();
        if ($customerId <= 0) {
            $this->webhookQueue->enqueueConsent($email, $subscriber->isSubscribed());
            return;
        }
        try {
            $customer = $this->customerFactory->create()->load($customerId);
            if (!$customer->getId() || !$this->storeScope->includesCustomer($customer)) {
                return;
            }
            $billing = $customer->getDefaultBillingAddress();
            $this->webhookQueue->enqueueConsent(
                $email,
                $subscriber->isSubscribed(),
                null !== $billing ? (string) $billing->getTelephone() : '',
                null !== $billing ? (string) $billing->getCountryId() : ''
            );
            $this->webhookQueue->enqueue(
                'customer',
                $customerId,
                $this->serializer->serializeCustomer($customer),
                'customer.updated'
            );
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
