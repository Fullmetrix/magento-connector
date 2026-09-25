<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\WebhookQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as CouponCollectionFactory;
use Magento\Framework\DB\Adapter\DeadlockException;

class SalesRuleSaveAfter implements ObserverInterface
{
    /**
     * @var array
     */
    private array $previousCouponIds = [];

    /**
     * @param WebhookQueue $webhookQueue
     * @param StoreScope $storeScope
     * @param CouponCollectionFactory $couponCollectionFactory
     */
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly StoreScope $storeScope,
        private readonly CouponCollectionFactory $couponCollectionFactory,
    ) {
    }

    /**
     * Records the rule, or the deletion of its coupons when it no longer has any in scope.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $key = null;
        try {
            $rule = $observer->getEvent()->getData('rule');
            if (!$rule instanceof Rule || !$rule->getRuleId()) {
                return;
            }
            $key = spl_object_id($rule);
            if ('salesrule_rule_save_before' === (string) $observer->getEvent()->getName()) {
                $this->previousCouponIds[$key] = $this->couponIds($rule);
                $key = null;
                return;
            }
            if ((int) $rule->getCouponType() === (int) Rule::COUPON_TYPE_NO_COUPON
                || !$this->storeScope->includesRule($rule)
            ) {
                $this->enqueueDeletedCoupons($rule, $this->previousCouponIds[$key] ?? []);
                return;
            }
            $this->webhookQueue->enqueue('coupon', (string) (int) $rule->getRuleId(), 'coupon.updated');
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        } finally {
            if (null !== $key) {
                unset($this->previousCouponIds[$key]);
            }
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
        $this->webhookQueue->enqueueDeletedMany('coupon', $ids);
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
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }

        return $ids;
    }
}
