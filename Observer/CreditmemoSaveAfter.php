<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;

class CreditmemoSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getData('creditmemo');
        if (!$creditmemo instanceof Creditmemo || !$creditmemo->getEntityId()) {
            return;
        }
        try {
            $this->webhookQueue->enqueue('refund', (int) $creditmemo->getEntityId(), $this->serializer->serializeRefund($creditmemo), 'refund.created');
            $order = $creditmemo->getOrder();
            if (null !== $order && $order->getEntityId()) {
                $this->webhookQueue->enqueue('order', (int) $order->getEntityId(), $this->serializer->serializeOrder($order), 'order.updated');
            }
        } catch (\Throwable) {
        }
    }
}
