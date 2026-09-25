<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\TrackingQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Framework\DB\Adapter\DeadlockException;

class CartAddProduct implements ObserverInterface
{
    /**
     * @param TrackingQueue $trackingQueue
     * @param CheckoutSession $checkoutSession
     * @param StoreScope $storeScope
     */
    public function __construct(
        private readonly TrackingQueue $trackingQueue,
        private readonly CheckoutSession $checkoutSession,
        private readonly StoreScope $storeScope,
    ) {
    }

    /**
     * Records the add to cart, the cron serializes the cart.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $product = $observer->getEvent()->getData('product');
            if (null === $product || !$product->getId()) {
                return;
            }
            $quote = $this->checkoutSession->getQuote();
            if ($quote instanceof Quote && !$this->storeScope->includesQuote($quote)) {
                return;
            }
            $this->trackingQueue->enqueue(
                'added_to_cart',
                [
                    'added_item' => [
                        'product_id' => (int) $product->getId(),
                        'name' => (string) $product->getName(),
                        'sku' => (string) $product->getSku(),
                        'price' => number_format((float) $product->getFinalPrice(), 2, '.', ''),
                    ],
                    'source' => 'server',
                ],
                null,
                $quote instanceof Quote ? (int) $quote->getId() : 0
            );
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
