<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as CustomerGroupCollectionFactory;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;

class CouponCommandHandler
{
    /**
     * @param RuleFactory $ruleFactory
     * @param CouponFactory $couponFactory
     * @param StoreSettingsProvider $storeSettings
     * @param CustomerGroupCollectionFactory $customerGroupCollectionFactory
     * @param Magento\Catalog\Model\ProductFactory $productFactory
     */
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly CouponFactory $couponFactory,
        private readonly StoreSettingsProvider $storeSettings,
        private readonly CustomerGroupCollectionFactory $customerGroupCollectionFactory,
        private readonly \Magento\Catalog\Model\ProductFactory $productFactory,
    ) {
    }

    /**
     * Handles the.
     *
     * @param string $action
     * @param array $payload
     * @return array
     */
    public function handle(string $action, array $payload): array
    {
        return match ($action) {
            'coupon.create' => $this->createCoupon($payload),
            'coupon.update' => $this->updateCoupon($payload),
            'coupon.delete' => $this->deleteCoupon($payload),
            default => ['success' => false, 'error' => 'unknown_action'],
        };
    }

    /**
     * Creates the coupon.
     *
     * @param array $payload
     * @return array
     */
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

    /**
     * Updates the coupon.
     *
     * @param array $payload
     * @return array
     */
    private function updateCoupon(array $payload): array
    {
        if (null !== $this->generatedCouponReference($payload)) {
            return ['success' => false, 'error' => 'generated_coupon_read_only'];
        }
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $rule = null;
        if (isset($payload['id']) && ctype_digit((string) $payload['id']) && (int) $payload['id'] > 0) {
            $rule = $this->ruleFactory->create()->load((int) $payload['id']);
            if (!$rule->getRuleId() || !$this->ruleInScope($rule)) {
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

        return ['success' => true, 'data' => [
            'id' => (int) $rule->getRuleId(),
            'code' => (string) $rule->getCouponCode(),
        ]];
    }

    /**
     * Deletes the coupon.
     *
     * @param array $payload
     * @return array
     */
    private function deleteCoupon(array $payload): array
    {
        $generatedCouponReference = $this->generatedCouponReference($payload);
        if (null !== $generatedCouponReference) {
            try {
                [$ruleId, $couponId] = $generatedCouponReference;
                $coupon = $this->couponFactory->create()->load($couponId);
                if (!$coupon->getCouponId() || (int) $coupon->getRuleId() !== $ruleId) {
                    return ['success' => false, 'error' => 'coupon_not_found'];
                }
                $rule = $this->ruleFactory->create()->load($ruleId);
                if (!$rule->getRuleId() || !$this->ruleInScope($rule)) {
                    return ['success' => false, 'error' => 'coupon_not_found'];
                }
                $coupon->delete();

                return ['success' => true, 'data' => ['deleted' => true]];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'delete_failed: ' . $e->getMessage()];
            }
        }
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $rule = null;
        if (isset($payload['id']) && ctype_digit((string) $payload['id']) && (int) $payload['id'] > 0) {
            $rule = $this->ruleFactory->create()->load((int) $payload['id']);
            if (!$rule->getRuleId() || !$this->ruleInScope($rule)) {
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

    /**
     * Applies the payload.
     *
     * @param Rule $rule
     * @param string $code
     * @param array $payload
     * @return void
     */
    private function applyPayload(Rule $rule, string $code, array $payload): void
    {
        $isNew = !$rule->getRuleId();

        if ($isNew) {
            $customerGroupIds = [];
            foreach ($this->customerGroupCollectionFactory->create() as $group) {
                $customerGroupIds[] = (int) $group->getId();
            }
            $rule->setIsActive(1);
            $rule->setWebsiteIds([$this->storeSettings->getWebsiteId()]);
            $rule->setCustomerGroupIds($customerGroupIds);
        }

        $rule->setCouponType(Rule::COUPON_TYPE_SPECIFIC);
        $rule->setCouponCode($code);
        $rule->setUseAutoGeneration(0);

        if ($isNew || \array_key_exists('description', $payload)) {
            $rule->setName((string) ($payload['description'] ?? $code) ?: $code);
            $rule->setDescription((string) ($payload['description'] ?? ''));
        }
        if ($isNew || \array_key_exists('discountType', $payload)) {
            $rule->setSimpleAction(match ((string) ($payload['discountType'] ?? 'percentage')) {
                'fixed_cart' => 'cart_fixed',
                'fixed_product', 'fixed' => 'by_fixed',
                default => 'by_percent',
            });
        }
        if ($isNew || \array_key_exists('amount', $payload)) {
            $rule->setDiscountAmount((float) ($payload['amount'] ?? 0));
        }
        if ($isNew || \array_key_exists('freeShipping', $payload)) {
            $rule->setSimpleFreeShipping(!empty($payload['freeShipping']) ? '2' : '0');
        }
        if ($isNew || \array_key_exists('individualUse', $payload)) {
            $rule->setDiscardSubsequentRules(!empty($payload['individualUse']) ? 1 : 0);
        }
        if (\array_key_exists('usageLimit', $payload)) {
            $rule->setUsesPerCoupon((int) $payload['usageLimit']);
        }
        if (\array_key_exists('usageLimitPerUser', $payload)) {
            $rule->setUsesPerCustomer((int) $payload['usageLimitPerUser']);
        }
        if (\array_key_exists('startsAt', $payload)) {
            $rule->setFromDate(!empty($payload['startsAt']) ? $this->toDate((string) $payload['startsAt']) : null);
        }
        if (\array_key_exists('expiresAt', $payload)) {
            $rule->setToDate(!empty($payload['expiresAt']) ? $this->toDate((string) $payload['expiresAt']) : null);
        }

        if ($isNew || \array_key_exists('minimumAmount', $payload)) {
            $minimumAmount = (float) ($payload['minimumAmount'] ?? 0);
            $rule->setData('conditions_serialized', $minimumAmount > 0 ? json_encode([
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
                    'value' => (string) $minimumAmount,
                    'is_value_processed' => false,
                ]],
            ]) : null);
        }

        if ($isNew || \array_key_exists('productIds', $payload)) {
            $skus = $this->skusForProductIds(\is_array($payload['productIds'] ?? null) ? $payload['productIds'] : []);
            $rule->setData('actions_serialized', \count($skus) > 0 ? json_encode([
                'type' => \Magento\SalesRule\Model\Rule\Condition\Product\Combine::class,
                'attribute' => null,
                'operator' => null,
                'value' => '1',
                'is_value_processed' => null,
                'aggregator' => 'all',
                'conditions' => [[
                    'type' => \Magento\SalesRule\Model\Rule\Condition\Product::class,
                    'attribute' => 'sku',
                    'operator' => '()',
                    'value' => implode(',', $skus),
                    'is_value_processed' => false,
                ]],
            ]) : null);
        }
    }

    /**
     * Skus for product ids.
     *
     * @param array $productIds
     * @return array
     */
    private function skusForProductIds(array $productIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (0 === \count($ids)) {
            return [];
        }
        $skus = [];
        foreach ($ids as $id) {
            try {
                $product = $this->productFactory->create();
                // Direct resource load on purpose: the repository would run every product
                // plugin for a single SKU lookup on the export hot path.
                // phpcs:ignore Magento2.Methods.DeprecatedModelMethod
                $product->getResource()->load($product, $id);
                $sku = (string) $product->getSku();
                if ('' !== $sku) {
                    $skus[] = $sku;
                }
            // The failure is optional data, the caller keeps going.
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            } catch (\Throwable) {
            }
        }

        return $skus;
    }

    /**
     * Finds the rule by code.
     *
     * @param string $code
     * @return Rule|null
     */
    private function findRuleByCode(string $code): ?Rule
    {
        $coupon = $this->couponFactory->create()->loadByCode($code);
        if (!$coupon->getCouponId() || !$coupon->getRuleId()) {
            return null;
        }
        $rule = $this->ruleFactory->create()->load((int) $coupon->getRuleId());

        return $rule->getRuleId() && $this->ruleInScope($rule) ? $rule : null;
    }

    /**
     * To date.
     *
     * @param string $value
     * @return string
     */
    private function toDate(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Generated coupon reference.
     *
     * @param array $payload
     * @return array|null
     */
    private function generatedCouponReference(array $payload): ?array
    {
        $id = (string) ($payload['id'] ?? '');
        if (1 !== preg_match('/^(\d+):(\d+)$/', $id, $matches)) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * Rule in scope.
     *
     * @param Rule $rule
     * @return bool
     */
    private function ruleInScope(Rule $rule): bool
    {
        return \in_array(
            $this->storeSettings->getWebsiteId(),
            array_map('intval', $rule->getWebsiteIds()),
            true
        );
    }
}
