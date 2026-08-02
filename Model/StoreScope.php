<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Customer;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\SalesRule\Model\Rule;

class StoreScope
{
    public function __construct(private readonly StoreSettingsProvider $settings)
    {
    }

    public function includesOrder(Order $order): bool
    {
        return (int) $order->getStoreId() === $this->settings->getStoreId();
    }

    public function includesCreditmemo(Creditmemo $creditmemo): bool
    {
        $order = $creditmemo->getOrder();

        return null !== $order && $this->includesOrder($order);
    }

    public function includesCustomer(Customer $customer): bool
    {
        return (int) $customer->getWebsiteId() === $this->settings->getWebsiteId();
    }

    public function includesProduct(Product $product): bool
    {
        return \in_array($this->settings->getWebsiteId(), array_map('intval', $product->getWebsiteIds()), true);
    }

    public function includesCategory(Category $category): bool
    {
        $rootId = $this->settings->getRootCategoryId();
        $pathIds = array_map('intval', explode('/', (string) $category->getPath()));

        return (int) $category->getLevel() >= 2 && \in_array($rootId, $pathIds, true);
    }

    public function includesRule(Rule $rule): bool
    {
        return \in_array($this->settings->getWebsiteId(), array_map('intval', $rule->getWebsiteIds()), true);
    }

    public function includesQuote(Quote $quote): bool
    {
        return (int) $quote->getStoreId() === $this->settings->getStoreId();
    }

    public function includesWebsiteStore(int $storeId): bool
    {
        try {
            return (int) $this->settings->getStoreById($storeId)->getWebsiteId()
                === $this->settings->getWebsiteId();
        } catch (\Throwable) {
            return false;
        }
    }
}
