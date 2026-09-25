<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\DB\Adapter\DeadlockException;

class OrderSaveAfter implements ObserverInterface
{
    /**
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(private readonly WebhookQueue $webhookQueue)
    {
    }

    /**
     * Records the order identifier, the cron serializes the order once the transaction is committed.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof Order || !$order->getEntityId()) {
                return;
            }
            $this->webhookQueue->enqueue(
                'order',
                (int) $order->getEntityId(),
                $order->isObjectNew() ? 'order.created' : 'order.updated'
            );
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
