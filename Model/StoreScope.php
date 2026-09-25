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
    /**
     * @param StoreSettingsProvider $settings
     */
    public function __construct(private readonly StoreSettingsProvider $settings)
    {
    }

    /**
     * Includes order.
     *
     * @param Order $order
     * @return bool
     */
    public function includesOrder(Order $order): bool
    {
        return (int) $order->getStoreId() === $this->settings->getStoreId();
    }

    /**
     * Includes creditmemo.
     *
     * @param Creditmemo $creditmemo
     * @return bool
     */
    public function includesCreditmemo(Creditmemo $creditmemo): bool
    {
        $order = $creditmemo->getOrder();

        return null !== $order && $this->includesOrder($order);
    }

    /**
     * Includes customer.
     *
     * @param Customer $customer
     * @return bool
     */
    public function includesCustomer(Customer $customer): bool
    {
        return (int) $customer->getWebsiteId() === $this->settings->getWebsiteId();
    }

    /**
     * Includes product.
     *
     * @param Product $product
     * @return bool
     */
    public function includesProduct(Product $product): bool
    {
        return \in_array($this->settings->getWebsiteId(), array_map('intval', $product->getWebsiteIds()), true);
    }

    /**
     * Includes category.
     *
     * @param Category $category
     * @return bool
     */
    public function includesCategory(Category $category): bool
    {
        $rootId = $this->settings->getRootCategoryId();
        $pathIds = array_map('intval', explode('/', (string) $category->getPath()));

        return (int) $category->getLevel() >= 2 && \in_array($rootId, $pathIds, true);
    }

    /**
     * Includes rule.
     *
     * @param Rule $rule
     * @return bool
     */
    public function includesRule(Rule $rule): bool
    {
        return \in_array($this->settings->getWebsiteId(), array_map('intval', $rule->getWebsiteIds()), true);
    }

    /**
     * Includes quote.
     *
     * @param Quote $quote
     * @return bool
     */
    public function includesQuote(Quote $quote): bool
    {
        return (int) $quote->getStoreId() === $this->settings->getStoreId();
    }

    /**
     * Includes website store.
     *
     * @param int $storeId
     * @return bool
     */
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
