<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;

class CreditmemoSaveAfter implements ObserverInterface
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
        $creditmemo = $observer->getEvent()->getData('creditmemo');
        if (!$creditmemo instanceof Creditmemo
            || !$creditmemo->getEntityId()
            || !$this->storeScope->includesCreditmemo($creditmemo)
        ) {
            return;
        }
        try {
            $this->webhookQueue->enqueue(
                'refund',
                (int) $creditmemo->getEntityId(),
                $this->serializer->serializeRefund($creditmemo),
                'refund.created'
            );
            $order = $creditmemo->getOrder();
            if (null !== $order && $order->getEntityId()) {
                $this->webhookQueue->enqueue(
                    'order',
                    (int) $order->getEntityId(),
                    $this->serializer->serializeOrder($order),
                    'order.updated'
                );
            }
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
