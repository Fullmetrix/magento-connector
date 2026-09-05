<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\WebhookQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as CouponCollectionFactory;

class SalesRuleSaveAfter implements ObserverInterface
{
    /**
     * @var array
     */
    private array $previousCouponIds = [];

    /**
     * @param WebhookQueue $webhookQueue
     * @param EntitySerializer $serializer
     * @param StoreScope $storeScope
     * @param CouponCollectionFactory $couponCollectionFactory
     */
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly EntitySerializer $serializer,
        private readonly StoreScope $storeScope,
        private readonly CouponCollectionFactory $couponCollectionFactory,
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
        $rule = $observer->getEvent()->getData('rule');
        if (!$rule instanceof Rule || !$rule->getRuleId()) {
            return;
        }
        $key = spl_object_id($rule);
        if ('salesrule_rule_save_before' === (string) $observer->getEvent()->getName()) {
            $this->previousCouponIds[$key] = $this->couponIds($rule);
            return;
        }
        if ((int) $rule->getCouponType() === (int) Rule::COUPON_TYPE_NO_COUPON) {
            $this->enqueueDeletedCoupons($rule, $this->previousCouponIds[$key] ?? []);
            unset($this->previousCouponIds[$key]);
            return;
        }
        try {
            if (!$this->storeScope->includesRule($rule)) {
                $this->enqueueDeletedCoupons($rule, $this->previousCouponIds[$key] ?? []);
                return;
            }
            $payload = $this->serializer->serializeCoupon($rule);
            if ('' === trim((string) ($payload['code'] ?? ''))) {
                return;
            }
            $this->webhookQueue->enqueue('coupon', (string) $payload['id'], $payload, 'coupon.updated');
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        } finally {
            unset($this->previousCouponIds[$key]);
        }
    }

    /**
     * Enqueue deleted coupons.
     *
     * @param Rule $rule
     * @param array $previousIds
     * @return void
     */
    private function enqueueDeletedCoupons(Rule $rule, array $previousIds = []): void
    {
        $ids = $previousIds;
        foreach ($this->couponIds($rule) as $id) {
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        if (0 === \count($ids)) {
            $ids[] = (int) $rule->getRuleId();
        }
        foreach ($ids as $id) {
            $this->webhookQueue->enqueueDeleted('coupon', $id);
        }
    }

    /**
     * Coupon ids.
     *
     * @param Rule $rule
     * @return array
     */
    private function couponIds(Rule $rule): array
    {
        $ids = [];
        try {
            $coupons = $this->couponCollectionFactory->create()
                ->addFieldToFilter('rule_id', (int) $rule->getRuleId());
            foreach ($coupons as $coupon) {
                $ids[] = (bool) $coupon->getIsPrimary()
                    ? (int) $rule->getRuleId()
                    : (int) $rule->getRuleId() . ':' . (int) $coupon->getCouponId();
            }
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }

        return $ids;
    }
}
