<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\TrackingQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Customer\Model\Customer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\DB\Adapter\DeadlockException;

class CustomerLogin implements ObserverInterface
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
     * Records who logged in.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $customer = $observer->getEvent()->getData('customer');
            if (!$customer instanceof Customer
                || !$customer->getId()
                || !$this->storeScope->includesCustomer($customer)
            ) {
                return;
            }
            $this->trackingQueue->enqueue('identify', [], [
                'email' => (string) $customer->getEmail(),
                'first_name' => (string) $customer->getFirstname(),
                'last_name' => (string) $customer->getLastname(),
                'customer_id' => (int) $customer->getId(),
                'identified_at' => (int) round(microtime(true) * 1000),
            ]);
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
