<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\TrackingQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Framework\DB\Adapter\DeadlockException;

class CartSaveAfter implements ObserverInterface
{
    /**
     * @param TrackingQueue $trackingQueue
     * @param StoreScope $storeScope
     */
    public function __construct(
        private readonly TrackingQueue $trackingQueue,
        private readonly StoreScope $storeScope,
    ) {
    }

    /**
     * Records the cart change, the cron serializes the cart.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $cart = $observer->getEvent()->getData('cart');
            $quote = null !== $cart && method_exists($cart, 'getQuote') ? $cart->getQuote() : null;
            if (!$quote instanceof Quote || !$quote->getId() || !$this->storeScope->includesQuote($quote)) {
                return;
            }
            $this->trackingQueue->enqueue('cart_updated', ['source' => 'server'], null, (int) $quote->getId(), true);
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
