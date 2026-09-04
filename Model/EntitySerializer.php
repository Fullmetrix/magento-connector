<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Model\Customer;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\RuleFactory;

class EntitySerializer
{
    private const META_VALUE_MAX_LENGTH = 20000;

    private const META_TOTAL_MAX_LENGTH = 65536;

    private const META_SHORT_VALUE_LENGTH = 512;

    private const SENSITIVE_KEYS = [
        'password_hash', 'rp_token', 'rp_token_created_at', 'confirmation',
        'protect_code', 'x_forwarded_for',
    ];

    private const ORDER_MAPPED_KEYS = [
        'entity_id', 'increment_id', 'status', 'state',
        'order_currency_code', 'base_currency_code', 'base_to_order_rate',
        'grand_total', 'subtotal', 'discount_amount', 'shipping_amount', 'tax_amount',
        'created_at', 'updated_at', 'customer_id', 'customer_email',
        'customer_group_id', 'customer_note', 'remote_ip',
        'store_id', 'store_name', 'coupon_code', 'shipping_method',
        'shipping_description', 'shipping_tax_amount', 'total_paid', 'total_refunded',
    ];

    private const CUSTOMER_MAPPED_KEYS = [
        'entity_id', 'email', 'firstname', 'lastname', 'prefix', 'suffix',
        'dob', 'taxvat', 'group_id', 'website_id', 'store_id',
        'created_at', 'updated_at',
    ];

    private const PRODUCT_MAPPED_KEYS = [
        'entity_id', 'name', 'url_key', 'sku', 'type_id', 'status',
        'description', 'short_description', 'price', 'special_price',
        'special_from_date', 'special_to_date', 'weight',
        'created_at', 'updated_at', 'manufacturer', 'image', 'category_ids',
    ];

    private ?array $categoryNameCache = null;
    private ?array $customerGroupCache = null;
    private ?array $productSalesCache = null;
    private array $ruleCache = [];
    private array $ruleCouponDetailsCache = [];

    public function __construct(
        private readonly StockProvider $stockProvider,
        private readonly Configurable $configurableType,
        private readonly SubscriberFactory $subscriberFactory,
        private readonly \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        private readonly \Magento\Catalog\Model\ProductFactory $productFactory,
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection,
        private readonly StoreSettingsProvider $storeSettings,
        private readonly RuleFactory $ruleFactory,
    ) {
    }

    public function serializeOrder(Order $order): array
    {
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress() ?: $billing;
        $payment = $order->getPayment();
        $baseToOrderRate = (float) $order->getBaseToOrderRate();

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
            $tracks = [];
            foreach ($order->getTracksCollection() as $track) {
                $tracks[] = $track;
            }
            $tracks = 0 === \count($tracks) ? [null] : $tracks;
            foreach ($tracks as $index => $track) {
                $shippingLines[] = [
                    'id' => ($shippingMethod ?: 'shipping') . (null !== $track ? ':' . (string) $track->getEntityId() : ''),
                    'method_title' => (string) $order->getShippingDescription(),
                    'method_id' => $shippingMethod,
                    'total' => $this->money(0 === $index ? (float) $order->getShippingAmount() : 0),
                    'total_tax' => $this->money(0 === $index ? (float) $order->getShippingTaxAmount() : 0),
                    'tracking_number' => null !== $track ? (string) $track->getTrackNumber() ?: null : null,
                    'carrier' => null !== $track ? (string) $track->getTitle() ?: null : null,
                ];
            }
        }

        $taxLines = $this->orderTaxLines((int) $order->getEntityId());
        if (0 === \count($taxLines) && (float) $order->getTaxAmount() > 0) {
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
            'base_currency' => (string) $order->getBaseCurrencyCode(),
            'base_to_order_rate' => $baseToOrderRate > 0 ? $baseToOrderRate : null,
            'order_to_base_rate' => $baseToOrderRate > 0 ? 1 / $baseToOrderRate : null,
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
            'customer_group_id' => (int) $order->getCustomerGroupId(),
            'customer_group' => $this->customerGroup((int) $order->getCustomerGroupId()),
            'customer_note' => (string) $order->getCustomerNote(),
            'customer_ip_address' => (string) $order->getRemoteIp() ?: null,
            'store' => [
                'id' => (int) $order->getStoreId(),
                'name' => (string) $order->getStoreName(),
                'website_id' => (int) $order->getStore()->getWebsiteId(),
            ],
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
            'meta_data' => $this->extraAttributesMeta($order->getData(), self::ORDER_MAPPED_KEYS),
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
        if (null === $mainImageUrl) {
            $imageFile = (string) $product->getImage();
            if ('' !== $imageFile && 'no_selection' !== $imageFile) {
                try {
                    $mediaBase = rtrim((string) $this->storeSettings->getStore()->getBaseUrl(
                        \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
                    ), '/');
                    $mainImageUrl = $mediaBase . '/catalog/product' . $imageFile;
                    $images[] = [
                        'id' => 0,
                        'src' => $mainImageUrl,
                        'alt' => (string) $product->getName(),
                        'position' => 0,
                    ];
                } catch (\Throwable) {
                }
            }
        }

        $payload = [
            'id' => (int) $customer->getId(),
            'email' => (string) $customer->getEmail(),
            'first_name' => (string) $customer->getFirstname(),
            'last_name' => (string) $customer->getLastname(),
            'prefix' => (string) $customer->getPrefix(),
            'suffix' => (string) $customer->getSuffix(),
            'date_of_birth' => $this->iso((string) $customer->getDob()),
            'vat_number' => (string) $customer->getTaxvat() ?: null,
            'phone' => null !== $billing ? (string) $billing->getTelephone() : null,
            'company' => null !== $billing ? (string) $billing->getCompany() : null,
            'city' => null !== $billing ? (string) $billing->getCity() : null,
            'country' => null !== $billing ? (string) $billing->getCountryId() : null,
            'newsletter' => $newsletter,
            'customer_group_id' => (int) $customer->getGroupId(),
            'customer_group' => $this->customerGroup((int) $customer->getGroupId()),
            'website_id' => (int) $customer->getWebsiteId(),
            'store_id' => (int) $customer->getStoreId(),
            'date_created' => $this->iso((string) $customer->getCreatedAt()),
            'date_modified' => $this->iso((string) $customer->getUpdatedAt()),
            'meta_data' => $this->extraAttributesMeta($customer->getData(), self::CUSTOMER_MAPPED_KEYS),
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
        $product->setStoreId($this->storeSettings->getStoreId());
        $product->unsetData('final_price');
        $parentId = $this->parentIdForChild((int) $product->getId());
        $isVariation = null !== $parentId;

        $stock = $this->stockProvider->get($product);

        $categoryIds = [];
        $categories = [];
        foreach (array_values(array_map('intval', $product->getCategoryIds() ?: [])) as $categoryId) {
            $name = $this->categoryName($categoryId);
            if (null !== $name) {
                $categoryIds[] = $categoryId;
                $categories[] = ['id' => $categoryId, 'name' => $name];
            }
        }

        $images = [];
        $mainImageUrl = null;
        try {
            $mediaBase = rtrim((string) $this->storeSettings->getStore()->getBaseUrl(
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
        $finalPrice = (float) $product->getFinalPrice();
        if ($finalPrice <= 0 && $price > 0) {
            $finalPrice = $price;
        }
        $salePrice = $finalPrice > 0 && ($finalPrice < $price || 0.0 === $price) ? $finalPrice : null;

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
        try {
            $permalink = (string) $product->getProductUrl();
        } catch (\Throwable) {
        }

        $ean = $this->firstAttributeValue($product, ['ean', 'ean13', 'gtin']);

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
            'sale_price' => null !== $salePrice ? $this->money($salePrice) : null,
            'on_sale' => null !== $salePrice,
            'date_on_sale_from' => $this->iso((string) $product->getSpecialFromDate()),
            'date_on_sale_to' => $this->iso((string) $product->getSpecialToDate()),
            'stock_status' => $stock['status'],
            'stock_quantity' => $stock['quantity'],
            'manage_stock' => $stock['manage'],
            'weight' => null !== $product->getWeight() ? (string) $product->getWeight() : null,
            'length' => $this->firstAttributeValue($product, ['length']),
            'width' => $this->firstAttributeValue($product, ['width']),
            'height' => $this->firstAttributeValue($product, ['height']),
            'wholesale_price' => $this->nullableMoney($product->getData('cost')),
            'ean' => $ean,
            'ean13' => $ean,
            'upc' => $this->firstAttributeValue($product, ['upc']),
            'isbn' => $this->firstAttributeValue($product, ['isbn']),
            'mpn' => $this->firstAttributeValue($product, ['mpn', 'manufacturer_part_number']),
            'condition' => $this->firstAttributeValue($product, ['condition']),
            'color' => $this->firstAttributeValue($product, ['color']),
            'size' => $this->firstAttributeValue($product, ['size']),
            'material' => $this->firstAttributeValue($product, ['material']),
            'supplier_name' => $this->firstAttributeValue($product, ['supplier_name', 'supplier']),
            'supplier_reference' => $this->firstAttributeValue($product, ['supplier_reference', 'supplier_sku']),
            'tax_class' => (string) $product->getTaxClassId(),
            'brand' => $brand,
            'manufacturer_name' => $brand,
            'category_ids' => $categoryIds,
            'categories' => $categories,
            'images' => $images,
            'image_url' => $mainImageUrl,
            'attributes' => $attributes,
            'features' => $this->productFeatures($product),
            'tags' => [],
            'total_sales' => $this->productSales((int) $product->getId()),
            'date_created' => $this->iso((string) $product->getCreatedAt()),
            'date_modified' => $this->iso((string) $product->getUpdatedAt()),
            'meta_data' => $this->extraAttributesMeta($product->getData(), self::PRODUCT_MAPPED_KEYS),
        ];
    }

    public function serializeCategory(Category $category): array
    {
        $parentId = (int) $category->getParentId();
        $rootCategoryId = $this->storeSettings->getRootCategoryId();
        $imageUrl = null;
        $image = trim((string) $category->getImage());
        if ('' !== $image) {
            try {
                $mediaBase = rtrim((string) $this->storeSettings->getStore()->getBaseUrl(
                    \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
                ), '/');
                $imageUrl = $mediaBase . '/catalog/category/' . ltrim($image, '/');
            } catch (\Throwable) {
            }
        }

        return [
            'id' => (int) $category->getId(),
            'name' => (string) $category->getName(),
            'slug' => (string) $category->getUrlKey(),
            'parent_id' => $parentId > 0 && $parentId !== $rootCategoryId ? $parentId : null,
            'description' => (string) $category->getData('description'),
            'count' => (int) $category->getProductCount(),
            'image_url' => $imageUrl,
            'position' => (int) $category->getPosition(),
            'date_created' => $this->iso((string) $category->getCreatedAt()),
            'date_modified' => $this->iso((string) $category->getUpdatedAt()),
            'meta_data' => $this->extraAttributesMeta($category->getData(), [
                'entity_id', 'name', 'url_key', 'parent_id', 'description',
                'product_count', 'image', 'position', 'created_at', 'updated_at',
            ]),
        ];
    }

    public function serializeCoupon(Rule|Coupon $source): array
    {
        $coupon = $source instanceof Coupon ? $source : $source->getPrimaryCoupon();
        $rule = $source instanceof Rule ? $source : $this->ruleForCoupon($source);
        $code = (string) ($coupon ? $coupon->getCode() : '');
        $couponId = $coupon ? (int) $coupon->getCouponId() : 0;
        $externalId = $coupon && !(bool) $coupon->getIsPrimary()
            ? (string) $rule->getRuleId() . ':' . $couponId
            : (int) $rule->getRuleId();

        $details = $this->ruleCouponDetails($rule);

        return [
            'id' => $externalId,
            'code' => $code,
            'description' => (string) $rule->getName(),
            'discount_type' => $details['discount_type'],
            'amount' => $this->money((float) $rule->getDiscountAmount()),
            'usage_count' => $coupon ? (int) $coupon->getTimesUsed() : 0,
            'usage_limit' => $coupon && $coupon->getUsageLimit() ? (int) $coupon->getUsageLimit() : ($rule->getUsesPerCoupon() ? (int) $rule->getUsesPerCoupon() : null),
            'usage_limit_per_user' => $coupon && $coupon->getUsagePerCustomer() ? (int) $coupon->getUsagePerCustomer() : ($rule->getUsesPerCustomer() ? (int) $rule->getUsesPerCustomer() : null),
            'individual_use' => (bool) $rule->getDiscardSubsequentRules(),
            'exclude_sale_items' => false,
            'free_shipping' => \in_array((string) $rule->getSimpleFreeShipping(), ['1', '2'], true),
            'minimum_amount' => $details['minimum_amount'],
            'maximum_amount' => $details['maximum_amount'],
            'product_ids' => $details['product_ids'],
            'excluded_product_ids' => $details['excluded_product_ids'],
            'product_categories' => $details['product_categories'],
            'excluded_product_categories' => $details['excluded_product_categories'],
            'date_created' => $coupon ? $this->iso((string) $coupon->getCreatedAt()) : null,
            'date_starts' => $this->iso((string) $rule->getFromDate()),
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
            $parentProductId = (int) $item->getProductId();
            $productId = $parentProductId;
            if (null !== $orderItem && 'configurable' === $orderItem->getProductType()) {
                foreach ($orderItem->getChildrenItems() as $child) {
                    $productId = (int) $child->getProductId();
                    break;
                }
            }
            $lineItems[] = [
                'id' => (int) $item->getEntityId(),
                'product_id' => $productId,
                'parent_id' => $productId !== $parentProductId ? $parentProductId : null,
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
            $metaData = [];
            $productOptions = $item->getProductOptions();
            foreach ($productOptions['attributes_info'] ?? [] as $attribute) {
                $metaData[] = [
                    'key' => (string) ($attribute['label'] ?? ''),
                    'value' => (string) ($attribute['value'] ?? ''),
                ];
            }

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
                'tax_rate' => (float) $item->getTaxPercent(),
                'meta_data' => $metaData,
            ];
        }

        return $items;
    }

    private function orderTaxLines(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }
        try {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()->from(
                $this->resourceConnection->getTableName('sales_order_tax'),
                ['tax_id', 'code', 'title', 'percent', 'amount', 'priority']
            )->where('order_id = ?', $orderId)->order('priority ASC');
            $shippingSelect = $connection->select()->from(
                ['tax_item' => $this->resourceConnection->getTableName('sales_order_tax_item')],
                [
                    'tax_id',
                    'shipping_amount' => new \Zend_Db_Expr('SUM(tax_item.real_amount)'),
                ]
            )->joinInner(
                ['tax' => $this->resourceConnection->getTableName('sales_order_tax')],
                'tax.tax_id = tax_item.tax_id',
                []
            )->where('tax.order_id = ?', $orderId)
                ->where('tax_item.taxable_item_type = ?', 'shipping')
                ->group('tax_item.tax_id');
            $shippingByTaxId = [];
            foreach ($connection->fetchAll($shippingSelect) as $row) {
                $shippingByTaxId[(int) $row['tax_id']] = (float) $row['shipping_amount'];
            }

            return array_map(function (array $row) use ($shippingByTaxId): array {
                $shippingAmount = $shippingByTaxId[(int) $row['tax_id']] ?? 0.0;

                return [
                    'id' => (int) $row['tax_id'],
                    'rate_id' => (string) $row['tax_id'],
                    'rate_code' => (string) $row['code'],
                    'label' => (string) $row['title'],
                    'rate_percent' => (float) $row['percent'],
                    'tax_total' => $this->money(max(0, (float) $row['amount'] - $shippingAmount)),
                    'shipping_tax_total' => $this->money($shippingAmount),
                    'compound' => false,
                ];
            }, $connection->fetchAll($select));
        } catch (\Throwable) {
            return [];
        }
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
            'vat_number' => (string) $address->getVatId(),
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
            'vat_number' => (string) $address->getVatId(),
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
        $product->setStoreId($this->storeSettings->getStoreId());
        $product->getResource()->load($product, $parentId);
        $this->parentStubCache = ['id' => $parentId, 'product' => $product];

        return $product;
    }

    private ?array $parentByChildCache = null;

    private function parentIdForChild(int $productId): ?int
    {
        $this->parentByChildCache ??= [];
        if (!\array_key_exists($productId, $this->parentByChildCache)) {
            $this->parentByChildCache[$productId] = null;
            try {
                $connection = $this->resourceConnection->getConnection();
                $select = $connection->select()->from(
                    $this->resourceConnection->getTableName('catalog_product_super_link'),
                    ['parent_id']
                )->where('product_id = ?', $productId)->limit(1);
                $parentId = $connection->fetchOne($select);
                if (false !== $parentId) {
                    $this->parentByChildCache[$productId] = (int) $parentId;
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
                $collection->setStoreId($this->storeSettings->getStoreId());
                $collection->addAttributeToSelect('name');
                $collection->addFieldToFilter('path', ['like' => '1/' . $this->storeSettings->getRootCategoryId() . '/%']);
                foreach ($collection as $category) {
                    $this->categoryNameCache[(int) $category->getId()] = (string) $category->getName();
                }
            } catch (\Throwable) {
            }
        }

        return $this->categoryNameCache[$categoryId] ?? null;
    }

    private function customerGroup(int $groupId): string
    {
        if (null === $this->customerGroupCache) {
            $this->customerGroupCache = [];
            try {
                $connection = $this->resourceConnection->getConnection();
                $select = $connection->select()->from(
                    $this->resourceConnection->getTableName('customer_group'),
                    ['customer_group_id', 'customer_group_code']
                );
                foreach ($connection->fetchAll($select) as $row) {
                    $this->customerGroupCache[(int) $row['customer_group_id']] = (string) $row['customer_group_code'];
                }
            } catch (\Throwable) {
            }
        }

        return $this->customerGroupCache[$groupId] ?? '';
    }

    private function productSales(int $productId): float
    {
        if (null === $this->productSalesCache) {
            $this->productSalesCache = [];
            try {
                $connection = $this->resourceConnection->getConnection();
                $select = $connection->select()->from(
                    ['item' => $this->resourceConnection->getTableName('sales_order_item')],
                    [
                        'product_id',
                        'total_sales' => new \Zend_Db_Expr('SUM(GREATEST(item.qty_ordered - item.qty_canceled - item.qty_refunded, 0))'),
                    ]
                )->joinInner(
                    ['orders' => $this->resourceConnection->getTableName('sales_order')],
                    'orders.entity_id = item.order_id',
                    []
                )->where('orders.store_id = ?', $this->storeSettings->getStoreId())
                    ->where('item.product_id IS NOT NULL')
                    ->group('item.product_id');
                foreach ($connection->fetchAll($select) as $row) {
                    $this->productSalesCache[(int) $row['product_id']] = (float) $row['total_sales'];
                }
            } catch (\Throwable) {
            }
        }

        return $this->productSalesCache[$productId] ?? 0.0;
    }

    private function productFeatures(Product $product): array
    {
        $features = [];
        foreach ($product->getAttributes() as $attribute) {
            if (!$attribute->getIsUserDefined()) {
                continue;
            }
            $label = trim((string) $attribute->getStoreLabel($this->storeSettings->getStoreId()));
            if ('' === $label) {
                continue;
            }
            try {
                $value = $attribute->getFrontend()->getValue($product);
            } catch (\Throwable) {
                continue;
            }
            if (\is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            }
            $value = trim((string) $value);
            if ('' === $value) {
                continue;
            }
            $features[] = ['name' => $label, 'value' => $value];
        }

        return $features;
    }

    private function couponProductRestrictions(Rule $rule): array
    {
        $includedSkus = [];
        $excludedSkus = [];
        $includedCategories = [];
        $excludedCategories = [];
        try {
            foreach ($this->flattenConditions($rule->getActions()->asArray()) as $condition) {
                $attribute = (string) ($condition['attribute'] ?? '');
                $operator = (string) ($condition['operator'] ?? '');
                $values = array_values(array_filter(array_map('trim', explode(',', (string) ($condition['value'] ?? '')))));
                if ('sku' === $attribute) {
                    foreach ($values as $value) {
                        if ('!()' === $operator) {
                            $excludedSkus[$value] = true;
                        } else {
                            $includedSkus[$value] = true;
                        }
                    }
                }
                if ('category_ids' === $attribute) {
                    foreach ($values as $value) {
                        $categoryId = (int) $value;
                        if ($categoryId > 0) {
                            if ('!()' === $operator) {
                                $excludedCategories[$categoryId] = true;
                            } else {
                                $includedCategories[$categoryId] = true;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        return [
            'product_ids' => $this->productIdsForSkus(array_keys($includedSkus)),
            'excluded_product_ids' => $this->productIdsForSkus(array_keys($excludedSkus)),
            'product_categories' => array_map('intval', array_keys($includedCategories)),
            'excluded_product_categories' => array_map('intval', array_keys($excludedCategories)),
        ];
    }

    private function ruleCouponDetails(Rule $rule): array
    {
        $ruleId = (int) $rule->getRuleId();
        if (isset($this->ruleCouponDetailsCache[$ruleId])) {
            return $this->ruleCouponDetailsCache[$ruleId];
        }

        $minimumAmount = null;
        $maximumAmount = null;
        try {
            foreach ($this->flattenConditions($rule->getConditions()->asArray()) as $condition) {
                $attribute = (string) ($condition['attribute'] ?? '');
                $operator = (string) ($condition['operator'] ?? '');
                if (\in_array($attribute, ['base_subtotal', 'base_subtotal_total_incl_tax'], true)
                    && \in_array($operator, ['>=', '>'], true)) {
                    $minimumAmount = (string) $condition['value'];
                }
                if (\in_array($attribute, ['base_subtotal', 'base_subtotal_total_incl_tax'], true)
                    && \in_array($operator, ['<=', '<'], true)) {
                    $maximumAmount = (string) $condition['value'];
                }
            }
        } catch (\Throwable) {
        }

        $details = [
            'discount_type' => match ((string) $rule->getSimpleAction()) {
                'by_percent' => 'percent',
                'cart_fixed' => 'fixed_cart',
                'by_fixed' => 'fixed_product',
                default => 'percent',
            },
            'minimum_amount' => $minimumAmount,
            'maximum_amount' => $maximumAmount,
            ...$this->couponProductRestrictions($rule),
        ];
        $this->ruleCouponDetailsCache[$ruleId] = $details;

        return $details;
    }

    private function ruleForCoupon(Coupon $coupon): Rule
    {
        $ruleId = (int) $coupon->getRuleId();
        if (!isset($this->ruleCache[$ruleId])) {
            $this->ruleCache[$ruleId] = $this->ruleFactory->create()->load($ruleId);
        }

        return $this->ruleCache[$ruleId];
    }

    private function productIdsForSkus(array $skus): array
    {
        if (0 === \count($skus)) {
            return [];
        }
        try {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()->from(
                $this->resourceConnection->getTableName('catalog_product_entity'),
                ['entity_id']
            )->where('sku IN (?)', $skus);

            return array_values(array_map('intval', $connection->fetchCol($select)));
        } catch (\Throwable) {
            return [];
        }
    }

    private function flattenConditions(array $condition): array
    {
        $result = [];
        foreach ($condition['conditions'] ?? [] as $child) {
            if (!\is_array($child)) {
                continue;
            }
            $result[] = $child;
            $result = array_merge($result, $this->flattenConditions($child));
        }

        return $result;
    }

    private function firstAttributeValue(Product $product, array $codes): ?string
    {
        foreach ($codes as $code) {
            try {
                $value = $product->getAttributeText($code);
            } catch (\Throwable) {
                $value = null;
            }
            if (\is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            }
            if (null === $value || '' === trim((string) $value)) {
                $value = $product->getData($code);
            }
            if (null !== $value && '' !== trim((string) $value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function nullableMoney(mixed $value): ?string
    {
        return null === $value || '' === trim((string) $value) ? null : $this->money($value);
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

    /**
     * Turn every attribute that is not already exposed as a top-level field into
     * meta_data, so custom EAV attributes reach the platform without having to
     * be whitelisted here first.
     *
     * @param array<string, mixed> $data
     * @param list<string> $mappedKeys
     *
     * @return list<array{key: string, value: string}>
     */
    private function extraAttributesMeta(array $data, array $mappedKeys): array
    {
        $short = [];
        $long = [];
        foreach ($data as $key => $value) {
            if (\in_array($key, $mappedKeys, true) || \in_array($key, self::SENSITIVE_KEYS, true)) {
                continue;
            }
            if (null === $value || !\is_scalar($value)) {
                continue;
            }
            $text = \is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            if ('' === $text) {
                continue;
            }
            // A custom attribute can hold binary or badly encoded bytes, which
            // would make the payload unserializable and lose the whole entity.
            if (1 !== preg_match('//u', $text)) {
                continue;
            }
            if (\strlen($text) <= self::META_SHORT_VALUE_LENGTH) {
                $short[] = ['key' => (string) $key, 'value' => $text];
                continue;
            }
            $long[] = ['key' => (string) $key, 'value' => self::truncateUtf8($text, self::META_VALUE_MAX_LENGTH)];
        }

        // Short values are emitted first so a single bulky text attribute can
        // never push an identifier-sized field out of the payload.
        $meta = $short;
        $budget = self::META_TOTAL_MAX_LENGTH;
        foreach ($short as $item) {
            $budget -= \strlen($item['value']);
        }
        foreach ($long as $item) {
            if ($budget <= 0) {
                break;
            }
            $meta[] = [
                'key' => $item['key'],
                'value' => \strlen($item['value']) > $budget
                    ? self::truncateUtf8($item['value'], $budget)
                    : $item['value'],
            ];
            $budget -= \strlen($item['value']);
        }

        return $meta;
    }

    /**
     * Cut on a character boundary: a byte-level cut splits a multibyte
     * character in two, which is enough to make the payload unserializable.
     */
    private static function truncateUtf8(string $text, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        return \strlen($text) <= $maxBytes ? $text : mb_strcut($text, 0, $maxBytes, 'UTF-8');
    }
}
