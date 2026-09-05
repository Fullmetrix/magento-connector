<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class OrderSaveAfter implements ObserverInterface
{
    /**
     * @param WebhookQueue $webhookQueue
     * @param EntitySerializer $serializer
     * @param StoreScope $storeScope
     */
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
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
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || !$order->getEntityId() || !$this->storeScope->includesOrder($order)) {
            return;
        }
        try {
            $event = $order->isObjectNew() ? 'order.created' : 'order.updated';
            $this->webhookQueue->enqueue(
                'order',
                (int) $order->getEntityId(),
                $this->serializer->serializeOrder($order),
                $event
            );
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
