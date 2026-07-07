<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\TrackingQueue;
use Magento\Customer\Model\Customer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CustomerLogin implements ObserverInterface
{
    public function __construct(private readonly TrackingQueue $trackingQueue)
    {
    }

    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getData('customer');
        if (!$customer instanceof Customer || !$customer->getId()) {
            return;
        }
        try {
            $this->trackingQueue->enqueue('identify', [], [
                'email' => (string) $customer->getEmail(),
                'first_name' => (string) $customer->getFirstname(),
                'last_name' => (string) $customer->getLastname(),
                'customer_id' => (int) $customer->getId(),
                'identified_at' => (int) round(microtime(true) * 1000),
            ]);
        } catch (\Throwable) {
        }
    }
}
