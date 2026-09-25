<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\CustomerFactory;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Framework\App\Area;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\OrderFactory;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\App\Emulation;

class WebhookPayloadResolver
{
    public const KIND_ECOMMERCE = 'ecommerce';
    public const KIND_CONSENT = 'consent';
    public const KIND_TRACKING = 'tracking';

    /**
     * @param EntitySerializer $serializer
     * @param CartSerializer $cartSerializer
     * @param StoreScope $storeScope
     * @param StoreSettingsProvider $storeSettings
     * @param OrderFactory $orderFactory
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param CustomerFactory $customerFactory
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryRepositoryInterface $categoryRepository
     * @param CouponFactory $couponFactory
     * @param RuleFactory $ruleFactory
     * @param SubscriberFactory $subscriberFactory
     * @param QuoteFactory $quoteFactory
     * @param Emulation $emulation
     */
    public function __construct(
        private readonly EntitySerializer $serializer,
        private readonly CartSerializer $cartSerializer,
        private readonly StoreScope $storeScope,
        private readonly StoreSettingsProvider $storeSettings,
        private readonly OrderFactory $orderFactory,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly CustomerFactory $customerFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CouponFactory $couponFactory,
        private readonly RuleFactory $ruleFactory,
        private readonly SubscriberFactory $subscriberFactory,
        private readonly QuoteFactory $quoteFactory,
        private readonly Emulation $emulation,
    ) {
    }

    /**
     * Turns a queue row into the delivery it stands for, null when nothing is left to send.
     *
     * @param string $entityType
     * @param string $entityId
     * @param string $event
     * @param array|null $data
     * @return array|null
     */
    public function resolve(string $entityType, string $entityId, string $event, ?array $data): ?array
    {
        if (WebhookQueue::TYPE_TRACKING === $entityType) {
            return null !== $data ? $this->tracking($data) : null;
        }
        if (WebhookQueue::TYPE_CONSENT === $entityType) {
            return null !== $data ? $this->consent($data) : null;
        }
        if (null !== $data) {
            return $this->ecommerce($entityType, $event, $data);
        }

        return match ($entityType) {
            'order' => $this->order((int) $entityId, $event),
            'refund' => $this->refund((int) $entityId, $event),
            'customer' => $this->customer((int) $entityId, $event),
            'product' => $this->product($entityId, $event),
            'category' => $this->category((int) $entityId, $event),
            'coupon' => $this->coupon($entityId, $event),
            default => null,
        };
    }

    /**
     * Releases what the serializers keep between two batches.
     *
     * @return void
     */
    public function release(): void
    {
        $this->serializer->releaseLoadedEntities();
        if (method_exists($this->productRepository, 'cleanCache')) {
            $this->productRepository->cleanCache();
        }
    }

    /**
     * Builds an ecommerce delivery.
     *
     * @param string $entityType
     * @param string $event
     * @param array $data
     * @return array
     */
    private function ecommerce(string $entityType, string $event, array $data): array
    {
        return [
            'kind' => self::KIND_ECOMMERCE,
            'key' => $entityType . ':' . (string) ($data['id'] ?? '') . ':' . ('deleted' === $event ? 'd' : 'u'),
            'entity_type' => $entityType,
            'event' => $event,
            'data' => $data,
        ];
    }

    /**
     * Builds the deletion of an entity that left the connected store.
     *
     * @param string $entityType
     * @param int|string $id
     * @return array
     */
    private function deleted(string $entityType, int|string $id): array
    {
        return $this->ecommerce($entityType, 'deleted', ['id' => (string) $id]);
    }

    /**
     * Resolves an order.
     *
     * @param int $id
     * @param string $event
     * @return array|null
     */
    private function order(int $id, string $event): ?array
    {
        $order = $this->orderFactory->create()->load($id);
        if (!$order->getEntityId() || !$this->storeScope->includesOrder($order)) {
            return null;
        }

        return $this->ecommerce('order', $event, $this->serializer->serializeOrder($order));
    }

    /**
     * Resolves a credit memo.
     *
     * @param int $id
     * @param string $event
     * @return array|null
     */
    private function refund(int $id, string $event): ?array
    {
        try {
            $creditmemo = $this->creditmemoRepository->get($id);
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return null;
        }
        if (!$creditmemo instanceof Creditmemo
            || !$creditmemo->getEntityId()
            || !$this->storeScope->includesCreditmemo($creditmemo)
        ) {
            return null;
        }

        return $this->ecommerce('refund', $event, $this->serializer->serializeRefund($creditmemo));
    }

    /**
     * Resolves a customer.
     *
     * @param int $id
     * @param string $event
     * @return array|null
     */
    private function customer(int $id, string $event): ?array
    {
        $customer = $this->customerFactory->create()->load($id);
        if (!$customer->getId()) {
            return null;
        }
        if (!$this->storeScope->includesCustomer($customer)) {
            return $this->deleted('customer', $id);
        }

        return $this->ecommerce('customer', $event, $this->serializer->serializeCustomer($customer));
    }

    /**
     * Resolves a product, by identifier or by SKU for the stock hooks.
     *
     * @param string $id
     * @param string $event
     * @return array|null
     */
    private function product(string $id, string $event): ?array
    {
        $storeId = $this->storeSettings->getStoreId();
        try {
            $product = str_starts_with($id, 'sku:')
                ? $this->productRepository->get(substr($id, 4), false, $storeId, true)
                : $this->productRepository->getById((int) $id, false, $storeId, true);
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return null;
        }
        if (!$product instanceof Product || !$product->getId()) {
            return null;
        }
        if (!$this->storeScope->includesProduct($product)) {
            return $this->deleted('product', (int) $product->getId());
        }

        return $this->ecommerce('product', $event, $this->serializer->serializeProduct($product));
    }

    /**
     * Resolves a category.
     *
     * @param int $id
     * @param string $event
     * @return array|null
     */
    private function category(int $id, string $event): ?array
    {
        try {
            $category = $this->categoryRepository->get($id, $this->storeSettings->getStoreId());
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return null;
        }
        if (!$category instanceof Category || !$category->getId()) {
            return null;
        }
        if (!$this->storeScope->includesCategory($category)) {
            return $this->deleted('category', $id);
        }

        return $this->ecommerce('category', $event, $this->serializer->serializeCategory($category));
    }

    /**
     * Resolves a coupon, `rule` for the primary coupon or `rule:coupon` for a generated one.
     *
     * @param string $id
     * @param string $event
     * @return array|null
     */
    private function coupon(string $id, string $event): ?array
    {
        $parts = explode(':', $id, 2);
        $rule = $this->ruleFactory->create()->load((int) $parts[0]);
        if (!$rule->getRuleId()) {
            return null;
        }
        if (!$this->storeScope->includesRule($rule)) {
            return $this->deleted('coupon', $id);
        }
        if (!isset($parts[1])) {
            $payload = $this->serializer->serializeCoupon($rule);

            return '' === trim((string) ($payload['code'] ?? ''))
                ? null
                : $this->ecommerce('coupon', $event, $payload);
        }
        $coupon = $this->couponFactory->create()->load((int) $parts[1]);
        if (!$coupon instanceof Coupon
            || !$coupon->getCouponId()
            || (int) $coupon->getRuleId() !== (int) $rule->getRuleId()
        ) {
            return null;
        }

        return $this->ecommerce('coupon', $event, $this->serializer->serializeCoupon($coupon));
    }

    /**
     * Resolves a consent row, reading the address or the subscription the hook did not read.
     *
     * @param array $data
     * @return array|null
     */
    private function consent(array $data): ?array
    {
        $email = (string) ($data['email'] ?? '');
        if ('' === $email) {
            return null;
        }
        if (isset($data['store_id']) && !$this->storeScope->includesWebsiteStore((int) $data['store_id'])) {
            return null;
        }
        $phone = (string) ($data['phone'] ?? '');
        $country = (string) ($data['country'] ?? '');
        $customerId = (int) ($data['customer_id'] ?? 0);
        if ($customerId > 0) {
            $customer = $this->customerFactory->create()->load($customerId);
            if (!$customer->getId() || !$this->storeScope->includesCustomer($customer)) {
                return null;
            }
            $billing = $customer->getDefaultBillingAddress();
            if ($billing) {
                $phone = (string) $billing->getTelephone();
                $country = strtoupper((string) $billing->getCountryId());
            }
        }
        if (!empty($data['check_subscription'])) {
            $subscriber = $this->subscriberFactory->create()->loadBySubscriberEmail(
                $email,
                (int) ($data['website_id'] ?? 0)
            );
            if (!$subscriber->getId() || !$subscriber->isSubscribed()) {
                return null;
            }
        }

        $consent = (bool) ($data['consent'] ?? false);

        return [
            'kind' => self::KIND_CONSENT,
            'key' => 'consent:' . $email . ':' . ($consent ? '1' : '0'),
            'data' => [
                'email' => $email,
                'phone' => $phone,
                'country' => $country,
                'consent' => $consent,
            ],
        ];
    }

    /**
     * Resolves a server side tracking event, the cart is read now rather than in the shopper request.
     *
     * @param array $data
     * @return array|null
     */
    private function tracking(array $data): ?array
    {
        $visitorId = (string) ($data['visitor_id'] ?? '');
        $sessionId = (string) ($data['session_id'] ?? '');
        $event = $data['event'] ?? null;
        if ('' === $visitorId || '' === $sessionId || !\is_array($event)) {
            return null;
        }
        $properties = \is_array($event['properties'] ?? null) ? $event['properties'] : [];
        $quoteId = (int) ($data['quote_id'] ?? 0);
        if ($quoteId > 0) {
            $quote = $this->quoteFactory->create()->loadByIdWithoutStore($quoteId);
            if ($quote->getId()) {
                if (!$this->storeScope->includesQuote($quote)) {
                    return null;
                }
                $properties['cart'] = $this->serializeCart($quote);
            } elseif (!empty($data['require_quote'])) {
                return null;
            }
        }
        $event['properties'] = (object) $properties;

        return [
            'kind' => self::KIND_TRACKING,
            'key' => 'tracking:' . (string) ($event['event_id'] ?? ''),
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'event' => $event,
        ];
    }

    /**
     * Serializes a cart as the storefront of its store view renders it, theme and base URL included.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return array
     */
    private function serializeCart(\Magento\Quote\Model\Quote $quote): array
    {
        $this->emulation->startEnvironmentEmulation((int) $quote->getStoreId(), Area::AREA_FRONTEND, true);
        try {
            return $this->cartSerializer->serialize($quote);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }
}
