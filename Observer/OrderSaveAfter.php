<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class OrderSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || !$order->getEntityId()) {
            return;
        }
        try {
            $event = $order->isObjectNew() ? 'order.created' : 'order.updated';
            $this->webhookQueue->enqueue('order', (int) $order->getEntityId(), $this->serializer->serializeOrder($order), $event);
        } catch (\Throwable) {
        }
    }
}
