<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\Subscriber;
use Magento\Framework\DB\Adapter\DeadlockException;

class SubscriberSaveAfter implements ObserverInterface
{
    /**
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(private readonly WebhookQueue $webhookQueue)
    {
    }

    /**
     * Records the consent change, the cron reads the customer and checks the scope.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $subscriber = $observer->getEvent()->getData('subscriber');
            if (!$subscriber instanceof Subscriber) {
                return;
            }
            $customerId = (int) $subscriber->getCustomerId();
            $this->webhookQueue->enqueueConsent(
                (string) $subscriber->getSubscriberEmail(),
                $subscriber->isSubscribed(),
                '',
                '',
                max(0, $customerId),
                (int) $subscriber->getStoreId()
            );
            if ($customerId > 0) {
                $this->webhookQueue->enqueue('customer', $customerId, 'customer.updated');
            }
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
