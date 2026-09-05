<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\CartSerializer;
use Fullmetrix\Connector\Model\TrackingQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;

class CartSaveAfter implements ObserverInterface
{
    /**
     * @param TrackingQueue $trackingQueue
     * @param CartSerializer $cartSerializer
     * @param StoreScope $storeScope
     */
    public function __construct(
        private readonly TrackingQueue $trackingQueue,
        private readonly CartSerializer $cartSerializer,
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
        $cart = $observer->getEvent()->getData('cart');
        $quote = null !== $cart && method_exists($cart, 'getQuote') ? $cart->getQuote() : null;
        if (!$quote instanceof Quote || !$quote->getId() || !$this->storeScope->includesQuote($quote)) {
            return;
        }
        try {
            $this->trackingQueue->enqueue('cart_updated', [
                'cart' => $this->cartSerializer->serialize($quote),
                'source' => 'server',
            ]);
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
