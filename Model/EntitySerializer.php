<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Model\Customer;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\SalesRule\Model\Rule;
use Magento\Store\Model\StoreManagerInterface;

class EntitySerializer
{
    private ?array $categoryNameCache = null;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly Configurable $configurableType,
        private readonly SubscriberFactory $subscriberFactory,
        private readonly \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        private readonly \Magento\Catalog\Model\ProductFactory $productFactory,
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection,
    ) {
    }

    public function serializeOrder(Order $order): array
    {
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress() ?: $billing;
        $payment = $order->getPayment();

        $datePaid = null;
        if ((float) $order->getTotalPaid() > 0) {
            $invoices = $order->getInvoiceCollection();
            foreach ($invoices as $invoice) {
                $created = (string) $invoice->getCreatedAt();
                if ('' !== $created && (null === $datePaid || $created < $datePaid)) {
                    $datePaid = $created;
                }
            }
            if (null === $datePaid) {
                $datePaid = (string) $order->getCreatedAt();
            }
        }

        $couponLines = [];
        $couponCode = (string) $order->getCouponCode();
        if ('' !== $couponCode) {
            $couponLines[] = [
                'code' => $couponCode,
                'discount' => $this->money(abs((float) $order->getDiscountAmount())),
            ];
        }

        $shippingLines = [];
        $shippingMethod = (string) $order->getShippingMethod();
        if ('' !== $shippingMethod || (float) $order->getShippingAmount() > 0) {
            $trackingNumber = null;
            $trackingCarrier = null;
            foreach ($order->getTracksCollection() as $track) {
                $trackingNumber = (string) $track->getTrackNumber() ?: null;
                $trackingCarrier = (string) $track->getTitle() ?: null;
                break;
            }
            $shippingLines[] = [
                'id' => $shippingMethod ?: 'shipping',
                'method_title' => (string) $order->getShippingDescription(),
                'method_id' => $shippingMethod,
                'total' => $this->money((float) $order->getShippingAmount()),
                'total_tax' => $this->money((float) $order->getShippingTaxAmount()),
                'tracking_number' => $trackingNumber,
                'carrier' => $trackingCarrier,
            ];
        }

        $taxLines = [];
        if ((float) $order->getTaxAmount() > 0) {
            $taxLines[] = ['total' => $this->money((float) $order->getTaxAmount())];
        }

        $payments = [];
        if (null !== $payment) {
            $methodTitle = '';
            try {
                $methodTitle = (string) $payment->getMethodInstance()->getTitle();
            } catch (\Throwable) {
                $methodTitle = (string) $payment->getMethod();
            }
            $payments[] = [
                'method' => (string) $payment->getMethod(),
                'method_title' => $methodTitle,
                'state' => (float) $order->getTotalPaid() >= (float) $order->getGrandTotal() ? 'completed' : 'pending',
                'amount' => $this->money((float) $order->getGrandTotal()),
                'transaction_id' => (string) $payment->getLastTransId() ?: null,
                'date' => $this->iso($datePaid),
            ];
        }

        $refundDates = [];
        if ((float) $order->getTotalRefunded() > 0) {
            foreach ($order->getCreditmemosCollection() as $creditmemo) {
                $refundDates[] = ['date' => $this->iso((string) $creditmemo->getCreatedAt())];
            }
        }

        $payload = [
            'id' => (int) $order->getEntityId(),
            'number' => (string) $order->getIncrementId(),
            'status' => (string) ($order->getStatus() ?: $order->getState() ?: 'pending'),
            'currency' => (string) $order->getOrderCurrencyCode(),
            'total' => $this->money((float) $order->getGrandTotal()),
            'subtotal' => $this->money((float) $order->getSubtotal()),
            'discount_total' => $this->money(abs((float) $order->getDiscountAmount())),
            'shipping_total' => $this->money((float) $order->getShippingAmount()),
            'total_tax' => $this->money((float) $order->getTaxAmount()),
            'date_created' => $this->iso((string) $order->getCreatedAt()),
            'date_modified' => $this->iso((string) $order->getUpdatedAt()),
            'date_paid' => $this->iso($datePaid),
            'date_completed' => 'complete' === $order->getState() ? $this->iso((string) $order->getUpdatedAt()) : null,
            'customer_id' => $order->getCustomerId() ? (int) $order->getCustomerId() : 0,
            'customer_email' => (string) $order->getCustomerEmail(),
            'customer_note' => (string) $order->getCustomerNote(),
            'created_via' => $order->getRemoteIp() ? 'checkout' : 'admin',
            'payment_method' => null !== $payment ? (string) $payment->getMethod() : '',
            'payment_method_title' => $payments[0]['method_title'] ?? '',
            'transaction_id' => $payments[0]['transaction_id'] ?? null,
            'line_items' => $this->lineItems($order),
            'shipping_lines' => $shippingLines,
            'coupon_lines' => $couponLines,
            'fee_lines' => [],
            'tax_lines' => $taxLines,
            'payments' => $payments,
            'refunds' => $refundDates,
        ];
        $billingPayload = $this->address($billing, (string) $order->getCustomerEmail());
        if (null !== $billingPayload) {
            $payload['billing'] = $billingPayload;
        }
        $shippingPayload = $this->address($shipping, (string) $order->getCustomerEmail());
        if (null !== $shippingPayload) {
            $payload['shipping'] = $shippingPayload;
        }

        return $payload;
    }

    public function serializeCustomer(Customer $customer): array
    {
        $billing = $customer->getDefaultBillingAddress() ?: null;
        $shipping = ($customer->getDefaultShippingAddress() ?: null) ?? $billing;

        $newsletter = false;
        try {
            $subscriber = $this->subscriberFactory->create()->loadByCustomer(
                (int) $customer->getId(),
                (int) $customer->getWebsiteId()
            );
            $newsletter = $subscriber->isSubscribed();
        } catch (\Throwable) {
        }

        $payload = [
            'id' => (int) $customer->getId(),
            'email' => (string) $customer->getEmail(),
            'first_name' => (string) $customer->getFirstname(),
            'last_name' => (string) $customer->getLastname(),
            'phone' => null !== $billing ? (string) $billing->getTelephone() : null,
            'company' => null !== $billing ? (string) $billing->getCompany() : null,
            'city' => null !== $billing ? (string) $billing->getCity() : null,
            'country' => null !== $billing ? (string) $billing->getCountryId() : null,
            'newsletter' => $newsletter,
            'date_created' => $this->iso((string) $customer->getCreatedAt()),
            'date_modified' => $this->iso((string) $customer->getUpdatedAt()),
        ];
        $billingPayload = $this->customerAddress($billing);
        if (null !== $billingPayload) {
            $payload['billing'] = $billingPayload;
        }
        $shippingPayload = $this->customerAddress($shipping);
        if (null !== $shippingPayload) {
            $payload['shipping'] = $shippingPayload;
        }

        return $payload;
    }

    public function serializeProduct(Product $product): array
    {
        $parentId = $this->parentIdForChild((int) $product->getId());
        $isVariation = null !== $parentId;

        $stockItem = null;
        try {
            $stockItem = $this->stockRegistry->getStockItem((int) $product->getId());
        } catch (\Throwable) {
        }

        $categoryIds = array_values(array_map('intval', $product->getCategoryIds() ?: []));
        $categories = [];
        foreach ($categoryIds as $categoryId) {
            $name = $this->categoryName($categoryId);
            if (null !== $name) {
                $categories[] = ['id' => $categoryId, 'name' => $name];
            }
        }

        $images = [];
        $mainImageUrl = null;
        try {
            $mediaBase = rtrim((string) $this->storeManager->getStore()->getBaseUrl(
                \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
            ), '/');
            $galleryImages = $product->getMediaGalleryEntries() ?? [];
            foreach ($galleryImages as $index => $entry) {
                $file = (string) $entry->getFile();
                if ('' === $file) {
                    continue;
                }
                $url = $mediaBase . '/catalog/product' . $file;
                if (null === $mainImageUrl) {
                    $mainImageUrl = $url;
                }
                $images[] = [
                    'id' => (int) ($entry->getId() ?: $index),
                    'src' => $url,
                    'alt' => (string) ($entry->getLabel() ?: ''),
                    'position' => (int) ($entry->getPosition() ?: $index),
                ];
            }
        } catch (\Throwable) {
        }

        $price = (float) $product->getPrice();
        $specialPrice = null !== $product->getSpecialPrice() && '' !== (string) $product->getSpecialPrice()
            ? (float) $product->getSpecialPrice()
            : null;
        $finalPrice = null !== $specialPrice && $specialPrice > 0 && ($specialPrice < $price || 0.0 === $price)
            ? $specialPrice
            : $price;

        $attributes = [];
        if ($isVariation) {
            try {
                $attributes = $this->configurableAttributesForChild($product, $parentId);
            } catch (\Throwable) {
            }
        }

        $brand = '';
        try {
            $brandText = $product->getAttributeText('manufacturer');
            if (\is_string($brandText)) {
                $brand = $brandText;
            }
        } catch (\Throwable) {
        }

        $typeId = (string) $product->getTypeId();
        $type = $isVariation ? 'variation' : match ($typeId) {
            'configurable' => 'variable',
            'grouped', 'bundle' => 'grouped',
            default => 'simple',
        };

        $urlKey = (string) $product->getUrlKey();
        $permalink = '';
        if ('' !== $urlKey) {
            try {
                $permalink = rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/') . '/' . $urlKey . '.html';
            } catch (\Throwable) {
            }
        }

        return [
            'id' => (int) $product->getId(),
            'name' => (string) $product->getName(),
            'slug' => $urlKey,
            'permalink' => $permalink,
            'sku' => (string) $product->getSku(),
            'type' => $type,
            'parent_id' => $parentId,
            'status' => (int) $product->getStatus() === ProductStatus::STATUS_ENABLED ? 'publish' : 'draft',
            'description' => (string) $product->getData('description'),
            'short_description' => (string) $product->getData('short_description'),
            'price' => $this->money($finalPrice),
            'regular_price' => $this->money($price),
            'sale_price' => null !== $specialPrice ? $this->money($specialPrice) : null,
            'on_sale' => null !== $specialPrice && $specialPrice > 0 && $specialPrice < $price,
            'date_on_sale_from' => $this->iso((string) $product->getSpecialFromDate()),
            'date_on_sale_to' => $this->iso((string) $product->getSpecialToDate()),
            'stock_status' => null !== $stockItem && $stockItem->getIsInStock() ? 'instock' : 'outofstock',
            'stock_quantity' => null !== $stockItem ? (int) $stockItem->getQty() : null,
            'manage_stock' => null !== $stockItem && (bool) $stockItem->getManageStock(),
            'weight' => null !== $product->getWeight() ? (string) $product->getWeight() : null,
            'brand' => $brand,
            'manufacturer_name' => $brand,
            'category_ids' => $categoryIds,
            'categories' => $categories,
            'images' => $images,
            'image_url' => $mainImageUrl,
            'attributes' => $attributes,
            'tags' => [],
            'total_sales' => 0,
            'date_created' => $this->iso((string) $product->getCreatedAt()),
            'date_modified' => $this->iso((string) $product->getUpdatedAt()),
        ];
    }

    public function serializeCategory(Category $category): array
    {
        $parentId = (int) $category->getParentId();

        return [
            'id' => (int) $category->getId(),
            'name' => (string) $category->getName(),
            'slug' => (string) $category->getUrlKey(),
            'parent_id' => $parentId > 2 ? $parentId : null,
            'description' => (string) $category->getData('description'),
            'count' => (int) $category->getProductCount(),
            'position' => (int) $category->getPosition(),
            'date_created' => $this->iso((string) $category->getCreatedAt()),
            'date_modified' => $this->iso((string) $category->getUpdatedAt()),
        ];
    }

    public function serializeCoupon(Rule $rule): array
    {
        $primaryCoupon = $rule->getPrimaryCoupon();
        $code = (string) ($primaryCoupon ? $primaryCoupon->getCode() : '');

        $discountType = match ((string) $rule->getSimpleAction()) {
            'by_percent' => 'percent',
            'cart_fixed' => 'fixed_cart',
            'by_fixed' => 'fixed_product',
            default => 'percent',
        };

        $minimumAmount = null;
        try {
            $conditions = $rule->getConditions()->asArray();
            foreach ($conditions['conditions'] ?? [] as $condition) {
                if (('base_subtotal' === ($condition['attribute'] ?? '') || 'base_subtotal_total_incl_tax' === ($condition['attribute'] ?? ''))
                    && \in_array($condition['operator'] ?? '', ['>=', '>'], true)) {
                    $minimumAmount = (string) $condition['value'];
                }
            }
        } catch (\Throwable) {
        }

        return [
            'id' => (int) $rule->getRuleId(),
            'code' => $code,
            'description' => (string) $rule->getName(),
            'discount_type' => $discountType,
            'amount' => $this->money((float) $rule->getDiscountAmount()),
            'usage_count' => $primaryCoupon ? (int) $primaryCoupon->getTimesUsed() : 0,
            'usage_limit' => $rule->getUsesPerCoupon() ? (int) $rule->getUsesPerCoupon() : null,
            'usage_limit_per_user' => $rule->getUsesPerCustomer() ? (int) $rule->getUsesPerCustomer() : null,
            'individual_use' => (bool) $rule->getDiscardSubsequentRules(),
            'exclude_sale_items' => false,
            'free_shipping' => \in_array((string) $rule->getSimpleFreeShipping(), ['1', '2'], true),
            'minimum_amount' => $minimumAmount,
            'date_created' => $this->iso((string) $rule->getFromDate()) ?? $this->iso(date('Y-m-d H:i:s')),
            'date_expires' => $this->iso((string) $rule->getToDate()),
            'status' => (bool) $rule->getIsActive() ? 'publish' : 'draft',
        ];
    }

    public function serializeRefund(Creditmemo $creditmemo): array
    {
        $order = $creditmemo->getOrder();
        $lineItems = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if (null !== $orderItem && null !== $orderItem->getParentItemId()) {
                continue;
            }
            $lineItems[] = [
                'id' => (int) $item->getEntityId(),
                'product_id' => (int) $item->getProductId(),
                'sku' => (string) $item->getSku(),
                'name' => (string) $item->getName(),
                'quantity' => (float) $item->getQty(),
                'total' => $this->money((float) $item->getRowTotal() - (float) $item->getDiscountAmount()),
                'total_tax' => $this->money((float) $item->getTaxAmount()),
            ];
        }

        return [
            'id' => (int) $creditmemo->getEntityId(),
            'parent_id' => (int) $creditmemo->getOrderId(),
            'order_id' => (int) $creditmemo->getOrderId(),
            'order_number' => null !== $order ? (string) $order->getIncrementId() : '',
            'amount' => $this->money((float) $creditmemo->getGrandTotal()),
            'currency' => (string) $creditmemo->getOrderCurrencyCode(),
            'reason' => (string) ($creditmemo->getCustomerNote() ?: ''),
            'customer_email' => null !== $order ? (string) $order->getCustomerEmail() : null,
            'date_created' => $this->iso((string) $creditmemo->getCreatedAt()),
            'line_items' => $lineItems,
        ];
    }

    private function lineItems(Order $order): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if (!$item instanceof OrderItem) {
                continue;
            }
            $variationId = 0;
            $productId = (int) $item->getProductId();
            if ('configurable' === $item->getProductType()) {
                foreach ($item->getChildrenItems() as $child) {
                    $variationId = (int) $child->getProductId();
                    break;
                }
            }
            $rowTotal = (float) $item->getRowTotal();
            $discount = (float) $item->getDiscountAmount();

            $items[] = [
                'id' => (int) $item->getItemId(),
                'name' => (string) $item->getName(),
                'product_id' => $productId,
                'variation_id' => $variationId,
                'sku' => (string) $item->getSku(),
                'quantity' => (int) $item->getQtyOrdered(),
                'price' => $this->money((float) $item->getPrice()),
                'subtotal' => $this->money($rowTotal),
                'total' => $this->money(max(0, $rowTotal - $discount)),
                'discount' => $this->money($discount),
                'total_tax' => $this->money((float) $item->getTaxAmount()),
            ];
        }

        return $items;
    }

    private function address(?\Magento\Sales\Api\Data\OrderAddressInterface $address, string $fallbackEmail = ''): ?array
    {
        if (null === $address) {
            return null;
        }
        $street = $address->getStreet() ?: [];

        return [
            'first_name' => (string) $address->getFirstname(),
            'last_name' => (string) $address->getLastname(),
            'company' => (string) $address->getCompany(),
            'address_1' => (string) ($street[0] ?? ''),
            'address_2' => (string) ($street[1] ?? ''),
            'city' => (string) $address->getCity(),
            'state' => (string) ($address->getRegion() ?: $address->getRegionCode() ?: ''),
            'postcode' => (string) $address->getPostcode(),
            'country' => (string) $address->getCountryId(),
            'email' => (string) ($address->getEmail() ?: $fallbackEmail),
            'phone' => (string) $address->getTelephone(),
        ];
    }

    private function customerAddress(?\Magento\Customer\Model\Address $address): ?array
    {
        if (null === $address) {
            return null;
        }
        $street = $address->getStreet() ?: [];

        return [
            'first_name' => (string) $address->getFirstname(),
            'last_name' => (string) $address->getLastname(),
            'company' => (string) $address->getCompany(),
            'address_1' => (string) ($street[0] ?? ''),
            'address_2' => (string) ($street[1] ?? ''),
            'city' => (string) $address->getCity(),
            'state' => (string) ($address->getRegion() ?: ''),
            'postcode' => (string) $address->getPostcode(),
            'country' => (string) $address->getCountryId(),
            'phone' => (string) $address->getTelephone(),
        ];
    }

    private function configurableAttributesForChild(Product $child, int $parentId): array
    {
        $attributes = [];
        $childData = $child->getData();
        foreach ($this->configurableType->getConfigurableAttributesAsArray($this->loadParentStub($parentId)) as $attribute) {
            $code = (string) ($attribute['attribute_code'] ?? '');
            if ('' === $code || !\array_key_exists($code, $childData)) {
                continue;
            }
            $value = $child->getAttributeText($code);
            if (\is_array($value)) {
                $value = implode(', ', $value);
            }
            if (!\is_string($value) || '' === $value) {
                continue;
            }
            $attributes[] = [
                'name' => (string) ($attribute['store_label'] ?? $attribute['frontend_label'] ?? $code),
                'option' => $value,
            ];
        }

        return $attributes;
    }

    private ?array $parentStubCache = null;

    private function loadParentStub(int $parentId): Product
    {
        if (null !== $this->parentStubCache && $this->parentStubCache['id'] === $parentId) {
            return $this->parentStubCache['product'];
        }
        $product = $this->productFactory->create();
        $product->getResource()->load($product, $parentId);
        $this->parentStubCache = ['id' => $parentId, 'product' => $product];

        return $product;
    }

    private ?array $parentByChildCache = null;

    private function parentIdForChild(int $productId): ?int
    {
        if (null === $this->parentByChildCache) {
            $this->parentByChildCache = [];
            try {
                $connection = $this->resourceConnection->getConnection();
                $select = $connection->select()->from(
                    $this->resourceConnection->getTableName('catalog_product_super_link'),
                    ['product_id', 'parent_id']
                );
                foreach ($connection->fetchAll($select) as $row) {
                    $childId = (int) $row['product_id'];
                    if (!isset($this->parentByChildCache[$childId])) {
                        $this->parentByChildCache[$childId] = (int) $row['parent_id'];
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $this->parentByChildCache[$productId] ?? null;
    }

    private function categoryName(int $categoryId): ?string
    {
        if (null === $this->categoryNameCache) {
            $this->categoryNameCache = [];
            try {
                $collection = $this->categoryCollectionFactory->create();
                $collection->addAttributeToSelect('name');
                foreach ($collection as $category) {
                    $this->categoryNameCache[(int) $category->getId()] = (string) $category->getName();
                }
            } catch (\Throwable) {
            }
        }

        return $this->categoryNameCache[$categoryId] ?? null;
    }

    private function money(float|int|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function iso(?string $value): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));

            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return null;
        }
    }
}
