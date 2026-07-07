<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as CustomerGroupCollectionFactory;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\StoreManagerInterface;

class CouponCommandHandler
{
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly CouponFactory $couponFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerGroupCollectionFactory $customerGroupCollectionFactory,
    ) {
    }

    public function handle(string $action, array $payload): array
    {
        return match ($action) {
            'coupon.create' => $this->createCoupon($payload),
            'coupon.update' => $this->updateCoupon($payload),
            'coupon.delete' => $this->deleteCoupon($payload),
            default => ['success' => false, 'error' => 'unknown_action'],
        };
    }

    private function createCoupon(array $payload): array
    {
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        if ('' === $code) {
            return ['success' => false, 'error' => 'missing_code'];
        }
        if (null !== $this->findRuleByCode($code)) {
            return ['success' => false, 'error' => 'code_already_exists'];
        }

        $rule = $this->ruleFactory->create();
        $this->applyPayload($rule, $code, $payload);

        try {
            $rule->save();
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'save_failed: ' . $e->getMessage()];
        }

        return ['success' => true, 'data' => ['id' => (int) $rule->getRuleId(), 'code' => $code]];
    }

    private function updateCoupon(array $payload): array
    {
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $rule = null;
        if (isset($payload['id']) && (int) $payload['id'] > 0) {
            $rule = $this->ruleFactory->create()->load((int) $payload['id']);
            if (!$rule->getRuleId()) {
                $rule = null;
            }
        }
        if (null === $rule && '' !== $code) {
            $rule = $this->findRuleByCode($code);
        }
        if (null === $rule) {
            return ['success' => false, 'error' => 'coupon_not_found'];
        }

        $this->applyPayload($rule, '' !== $code ? $code : (string) $rule->getCouponCode(), $payload);

        try {
            $rule->save();
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'save_failed: ' . $e->getMessage()];
        }

        return ['success' => true, 'data' => ['id' => (int) $rule->getRuleId(), 'code' => (string) $rule->getCouponCode()]];
    }

    private function deleteCoupon(array $payload): array
    {
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $rule = null;
        if (isset($payload['id']) && (int) $payload['id'] > 0) {
            $rule = $this->ruleFactory->create()->load((int) $payload['id']);
            if (!$rule->getRuleId()) {
                $rule = null;
            }
        }
        if (null === $rule && '' !== $code) {
            $rule = $this->findRuleByCode($code);
        }
        if (null === $rule) {
            return ['success' => false, 'error' => 'coupon_not_found'];
        }

        try {
            $rule->delete();
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'delete_failed: ' . $e->getMessage()];
        }

        return ['success' => true, 'data' => ['deleted' => true]];
    }

    private function applyPayload(Rule $rule, string $code, array $payload): void
    {
        $discountType = (string) ($payload['discountType'] ?? 'percentage');
        $amount = (float) ($payload['amount'] ?? 0);
        $freeShipping = (bool) ($payload['freeShipping'] ?? false);

        $simpleAction = match ($discountType) {
            'fixed_cart' => 'cart_fixed',
            'fixed_product', 'fixed' => 'by_fixed',
            default => 'by_percent',
        };

        $websiteIds = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteIds[] = (int) $website->getId();
        }
        $customerGroupIds = [];
        foreach ($this->customerGroupCollectionFactory->create() as $group) {
            $customerGroupIds[] = (int) $group->getId();
        }

        $rule->setName((string) ($payload['description'] ?? $code));
        $rule->setDescription((string) ($payload['description'] ?? ''));
        $rule->setIsActive(1);
        $rule->setCouponType(Rule::COUPON_TYPE_SPECIFIC);
        $rule->setCouponCode($code);
        $rule->setUseAutoGeneration(0);
        $rule->setSimpleAction($simpleAction);
        $rule->setDiscountAmount($amount);
        $rule->setSimpleFreeShipping($freeShipping ? '2' : '0');
        $rule->setWebsiteIds($websiteIds);
        $rule->setCustomerGroupIds($customerGroupIds);
        $rule->setDiscardSubsequentRules(!empty($payload['individualUse']) ? 1 : 0);

        if (!empty($payload['usageLimit'])) {
            $rule->setUsesPerCoupon((int) $payload['usageLimit']);
        }
        if (!empty($payload['usageLimitPerUser'])) {
            $rule->setUsesPerCustomer((int) $payload['usageLimitPerUser']);
        }
        if (!empty($payload['startsAt'])) {
            $rule->setFromDate($this->toDate((string) $payload['startsAt']));
        }
        if (!empty($payload['expiresAt'])) {
            $rule->setToDate($this->toDate((string) $payload['expiresAt']));
        }

        if (!empty($payload['minimumAmount']) && (float) $payload['minimumAmount'] > 0) {
            $rule->setData('conditions_serialized', json_encode([
                'type' => \Magento\SalesRule\Model\Rule\Condition\Combine::class,
                'attribute' => null,
                'operator' => null,
                'value' => '1',
                'is_value_processed' => null,
                'aggregator' => 'all',
                'conditions' => [[
                    'type' => \Magento\SalesRule\Model\Rule\Condition\Address::class,
                    'attribute' => 'base_subtotal',
                    'operator' => '>=',
                    'value' => (string) (float) $payload['minimumAmount'],
                    'is_value_processed' => false,
                ]],
            ]));
        }
    }

    private function findRuleByCode(string $code): ?Rule
    {
        $coupon = $this->couponFactory->create()->loadByCode($code);
        if (!$coupon->getCouponId() || !$coupon->getRuleId()) {
            return null;
        }
        $rule = $this->ruleFactory->create()->load((int) $coupon->getRuleId());

        return $rule->getRuleId() ? $rule : null;
    }

    private function toDate(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return $value;
        }
    }
}
