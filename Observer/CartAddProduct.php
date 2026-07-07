<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\TrackingQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CartAddProduct implements ObserverInterface
{
    public function __construct(private readonly TrackingQueue $trackingQueue)
    {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if (null === $product || !$product->getId()) {
            return;
        }
        try {
            $this->trackingQueue->enqueue('added_to_cart', [
                'product_id' => (int) $product->getId(),
                'product_name' => (string) $product->getName(),
                'sku' => (string) $product->getSku(),
                'price' => (float) $product->getFinalPrice(),
            ]);
        } catch (\Throwable) {
        }
    }
}
