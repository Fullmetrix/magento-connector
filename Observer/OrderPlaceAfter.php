<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\TrackingQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Sales\Model\Order;

class OrderPlaceAfter implements ObserverInterface
{
    /**
     * @param Config $config
     * @param TrackingQueue $trackingQueue
     * @param SubscriberFactory $subscriberFactory
     * @param StoreScope $storeScope
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(
        private readonly Config $config,
        private readonly TrackingQueue $trackingQueue,
        private readonly SubscriberFactory $subscriberFactory,
        private readonly StoreScope $storeScope,
        private readonly WebhookQueue $webhookQueue,
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
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || !$this->config->isActive() || !$this->storeScope->includesOrder($order)) {
            return;
        }

        $email = (string) $order->getCustomerEmail();
        if ('' === $email) {
            return;
        }

        try {
            $this->trackingQueue->enqueue('identify', [], [
                'email' => $email,
                'first_name' => (string) $order->getCustomerFirstname(),
                'last_name' => (string) $order->getCustomerLastname(),
                'customer_id' => $order->getCustomerId() ? (int) $order->getCustomerId() : null,
                'identified_at' => (int) round(microtime(true) * 1000),
            ]);
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }

        try {
            $subscribed = false;
            $subscriber = $this->subscriberFactory->create()->loadBySubscriberEmail(
                $email,
                (int) $order->getStore()->getWebsiteId()
            );
            if ($subscriber->getId()) {
                $subscribed = $subscriber->isSubscribed();
            }
            if (!$subscribed) {
                return;
            }

            $billing = $order->getBillingAddress();
            $this->webhookQueue->enqueueConsent(
                $email,
                true,
                null !== $billing ? (string) $billing->getTelephone() : '',
                null !== $billing ? (string) $billing->getCountryId() : ''
            );
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
