<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\CartSerializer;
use Fullmetrix\Connector\Model\TrackingQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;

class CartSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly TrackingQueue $trackingQueue,
        private readonly CartSerializer $cartSerializer,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $cart = $observer->getEvent()->getData('cart');
        $quote = null !== $cart && method_exists($cart, 'getQuote') ? $cart->getQuote() : null;
        if (!$quote instanceof Quote || !$quote->getId()) {
            return;
        }
        try {
            $this->trackingQueue->enqueue('cart_updated', [
                'cart' => $this->cartSerializer->serialize($quote),
                'source' => 'server',
            ]);
        } catch (\Throwable) {
        }
    }
}
