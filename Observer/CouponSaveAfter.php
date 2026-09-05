<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\StoreScope;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\RuleFactory;

class CouponSaveAfter implements ObserverInterface
{
    /**
     * @param WebhookQueue $webhookQueue
     * @param EntitySerializer $serializer
     * @param StoreScope $storeScope
     * @param RuleFactory $ruleFactory
     */
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
        private readonly StoreScope $storeScope,
        private readonly RuleFactory $ruleFactory,
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
        $coupon = $observer->getEvent()->getData('coupon');
        if (!$coupon instanceof Coupon || !$coupon->getCouponId()) {
            return;
        }
        try {
            $rule = $this->ruleFactory->create()->load((int) $coupon->getRuleId());
            if (!$rule->getRuleId()) {
                return;
            }
            if (!$this->storeScope->includesRule($rule)) {
                $id = (bool) $coupon->getIsPrimary()
                    ? (int) $rule->getRuleId()
                    : (int) $rule->getRuleId() . ':' . (int) $coupon->getCouponId();
                $this->webhookQueue->enqueueDeleted('coupon', $id);
                return;
            }
            $payload = $this->serializer->serializeCoupon($coupon);
            $this->webhookQueue->enqueue('coupon', (string) $payload['id'], $payload, 'coupon.updated');
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
