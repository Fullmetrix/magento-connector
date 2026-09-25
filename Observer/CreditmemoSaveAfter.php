<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Framework\DB\Adapter\DeadlockException;

class CreditmemoSaveAfter implements ObserverInterface
{
    /**
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(private readonly WebhookQueue $webhookQueue)
    {
    }

    /**
     * Records the credit memo and its order.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $creditmemo = $observer->getEvent()->getData('creditmemo');
            if (!$creditmemo instanceof Creditmemo || !$creditmemo->getEntityId()) {
                return;
            }
            $this->webhookQueue->enqueue('refund', (int) $creditmemo->getEntityId(), 'refund.created');
            $orderId = (int) $creditmemo->getOrderId();
            if ($orderId > 0) {
                $this->webhookQueue->enqueue('order', $orderId, 'order.updated');
            }
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
