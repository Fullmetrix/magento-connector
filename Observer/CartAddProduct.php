<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\CartSerializer;
use Fullmetrix\Connector\Model\TrackingQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;

class CartAddProduct implements ObserverInterface
{
    public function __construct(
        private readonly TrackingQueue $trackingQueue,
        private readonly CartSerializer $cartSerializer,
        private readonly CheckoutSession $checkoutSession,
        private readonly StoreScope $storeScope,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if (null === $product || !$product->getId()) {
            return;
        }
        try {
            $properties = [
                'added_item' => [
                    'product_id' => (int) $product->getId(),
                    'name' => (string) $product->getName(),
                    'sku' => (string) $product->getSku(),
                    'price' => number_format((float) $product->getFinalPrice(), 2, '.', ''),
                ],
                'source' => 'server',
            ];
            $quote = $this->checkoutSession->getQuote();
            if ($quote instanceof Quote && $quote->getId() && $this->storeScope->includesQuote($quote)) {
                $properties['cart'] = $this->cartSerializer->serialize($quote);
            } elseif ($quote instanceof Quote && !$this->storeScope->includesQuote($quote)) {
                return;
            }
            $this->trackingQueue->enqueue('added_to_cart', $properties);
        } catch (\Throwable) {
        }
    }
}
