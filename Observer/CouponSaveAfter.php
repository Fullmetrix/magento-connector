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
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
        private readonly StoreScope $storeScope,
        private readonly RuleFactory $ruleFactory,
    ) {
    }

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
        } catch (\Throwable) {
        }
    }
}
