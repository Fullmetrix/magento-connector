<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\StoreScope;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Customer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as CouponCollectionFactory;

class EntityDeleteAfter implements ObserverInterface
{
    private array $pendingDeletes = [];

    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly StoreScope $storeScope,
        private readonly RuleFactory $ruleFactory,
        private readonly CouponCollectionFactory $couponCollectionFactory,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $eventName = (string) $event->getName();
        if ('salesrule_rule_delete_before' === $eventName) {
            $rule = $event->getData('rule');
            if ($rule instanceof Rule && $this->storeScope->includesRule($rule)) {
                $this->pendingDeletes[$this->pendingKey('rule', $rule)] = $this->ruleCouponIds($rule);
            }
            return;
        }
        if ('salesrule_rule_delete_commit_after' === $eventName) {
            $rule = $event->getData('rule');
            $key = $this->pendingKey('rule', $rule);
            foreach ($this->pendingDeletes[$key] ?? [] as $id) {
                $this->webhookQueue->enqueueDeleted('coupon', $id);
            }
            unset($this->pendingDeletes[$key]);
            return;
        }

        $entity = match ($eventName) {
            'sales_order_delete_before', 'sales_order_delete_commit_after' => ['order', $event->getData('order')],
            'customer_delete_before', 'customer_delete_commit_after' => ['customer', $event->getData('customer')],
            'catalog_product_delete_before', 'catalog_product_delete_commit_after' => ['product', $event->getData('product')],
            'catalog_category_delete_before', 'catalog_category_delete_commit_after' => ['category', $event->getData('category')],
            'salesrule_coupon_delete_before', 'salesrule_coupon_delete_commit_after' => ['coupon', $event->getData('coupon')],
            'sales_order_creditmemo_delete_before', 'sales_order_creditmemo_delete_commit_after' => ['refund', $event->getData('creditmemo')],
            default => null,
        };
        if (null === $entity) {
            return;
        }
        $key = $this->pendingKey($entity[0], $entity[1]);
        if (str_ends_with($eventName, '_delete_before')) {
            $id = $this->entityId($entity[1]);
            if ((\is_int($id) && $id <= 0) || (\is_string($id) && '' === $id)) {
                return;
            }
            if ($this->isInScope($entity[1])) {
                $this->pendingDeletes[$key] = [$id];
            }
            return;
        }
        if (!isset($this->pendingDeletes[$key])) {
            return;
        }
        $id = $this->pendingDeletes[$key][0];

        try {
            $this->webhookQueue->enqueueDeleted($entity[0], $id);
        } catch (\Throwable) {
        } finally {
            unset($this->pendingDeletes[$key]);
        }
    }

    private function isInScope(mixed $entity): bool
    {
        return match (true) {
            $entity instanceof Order => $this->storeScope->includesOrder($entity),
            $entity instanceof Customer => $this->storeScope->includesCustomer($entity),
            $entity instanceof Product => $this->storeScope->includesProduct($entity),
            $entity instanceof Category => $this->storeScope->includesCategory($entity),
            $entity instanceof Rule => $this->storeScope->includesRule($entity),
            $entity instanceof Coupon => $this->couponIsInScope($entity),
            $entity instanceof Creditmemo => $this->storeScope->includesCreditmemo($entity),
            default => false,
        };
    }

    private function entityId(mixed $entity): int|string
    {
        if ($entity instanceof Coupon) {
            return (bool) $entity->getIsPrimary()
                ? (int) $entity->getRuleId()
                : (int) $entity->getRuleId() . ':' . (int) $entity->getCouponId();
        }

        return \is_object($entity) && method_exists($entity, 'getData')
            ? (int) $entity->getData('entity_id')
            : 0;
    }

    private function couponIsInScope(Coupon $coupon): bool
    {
        try {
            $rule = $this->ruleFactory->create()->load((int) $coupon->getRuleId());

            return $rule->getRuleId() && $this->storeScope->includesRule($rule);
        } catch (\Throwable) {
            return false;
        }
    }

    private function ruleCouponIds(Rule $rule): array
    {
        $ids = [];
        try {
            $collection = $this->couponCollectionFactory->create();
            $collection->addFieldToFilter('rule_id', (int) $rule->getRuleId());
            foreach ($collection as $coupon) {
                $ids[] = (bool) $coupon->getIsPrimary()
                    ? (int) $rule->getRuleId()
                    : (int) $rule->getRuleId() . ':' . (int) $coupon->getCouponId();
            }
        } catch (\Throwable) {
        }
        if (0 === \count($ids)) {
            $ids[] = (int) $rule->getRuleId();
        }

        return $ids;
    }

    private function pendingKey(string $entityType, mixed $entity): string
    {
        return \is_object($entity)
            ? $entityType . ':object:' . spl_object_id($entity)
            : $entityType . ':unknown';
    }
}
