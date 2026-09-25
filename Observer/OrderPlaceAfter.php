<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\TrackingQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\DB\Adapter\DeadlockException;

class OrderPlaceAfter implements ObserverInterface
{
    /**
     * @param TrackingQueue $trackingQueue
     * @param StoreScope $storeScope
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(
        private readonly TrackingQueue $trackingQueue,
        private readonly StoreScope $storeScope,
        private readonly WebhookQueue $webhookQueue,
    ) {
    }

    /**
     * Records who placed the order, the subscription is checked by the cron.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof Order || !$this->storeScope->includesOrder($order)) {
                return;
            }
            $email = (string) $order->getCustomerEmail();
            if ('' === $email) {
                return;
            }
            $this->trackingQueue->enqueue('identify', [], [
                'email' => $email,
                'first_name' => (string) $order->getCustomerFirstname(),
                'last_name' => (string) $order->getCustomerLastname(),
                'customer_id' => $order->getCustomerId() ? (int) $order->getCustomerId() : null,
                'identified_at' => (int) round(microtime(true) * 1000),
            ]);
            $billing = $order->getBillingAddress();
            $this->webhookQueue->enqueueOrderConsentCheck(
                $email,
                (int) $order->getStore()->getWebsiteId(),
                null !== $billing ? (string) $billing->getTelephone() : '',
                null !== $billing ? (string) $billing->getCountryId() : ''
            );
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
