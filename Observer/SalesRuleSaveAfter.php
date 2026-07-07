<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\SalesRule\Model\Rule;

class SalesRuleSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $rule = $observer->getEvent()->getData('rule');
        if (!$rule instanceof Rule || !$rule->getRuleId()) {
            return;
        }
        if ((int) $rule->getCouponType() === (int) Rule::COUPON_TYPE_NO_COUPON) {
            return;
        }
        try {
            $this->webhookQueue->enqueue('coupon', (int) $rule->getRuleId(), $this->serializer->serializeCoupon($rule), 'coupon.updated');
        } catch (\Throwable) {
        }
    }
}
