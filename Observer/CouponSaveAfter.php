<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\SalesRule\Model\Coupon;
use Magento\Framework\DB\Adapter\DeadlockException;

class CouponSaveAfter implements ObserverInterface
{
    /**
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(private readonly WebhookQueue $webhookQueue)
    {
    }

    /**
     * Records the coupon identifier, the cron loads its rule and serializes it.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $coupon = $observer->getEvent()->getData('coupon');
            if (!$coupon instanceof Coupon || !$coupon->getCouponId() || (int) $coupon->getRuleId() <= 0) {
                return;
            }
            $id = (bool) $coupon->getIsPrimary()
                ? (string) (int) $coupon->getRuleId()
                : (int) $coupon->getRuleId() . ':' . (int) $coupon->getCouponId();
            $this->webhookQueue->enqueue('coupon', $id, 'coupon.updated');
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
