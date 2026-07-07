<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;

class CartSerializer
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function serialize(Quote $quote): array
    {
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $variationId = null;
            if ('configurable' === $item->getProductType()) {
                foreach ($item->getChildren() as $child) {
                    $variationId = (int) $child->getProductId();
                    break;
                }
            }
            $items[] = [
                'product_id' => (int) $item->getProductId(),
                'variation_id' => $variationId,
                'name' => (string) $item->getName(),
                'quantity' => (float) $item->getQty(),
                'price' => $this->money((float) $item->getPrice()),
                'line_total' => $this->money((float) $item->getRowTotal()),
                'sku' => (string) $item->getSku(),
                'image_url' => null,
                'url' => null,
            ];
        }

        $couponCodes = [];
        $couponCode = (string) $quote->getCouponCode();
        if ('' !== $couponCode) {
            $couponCodes[] = $couponCode;
        }

        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        $subtotal = (float) $quote->getSubtotal();
        $subtotalWithDiscount = (float) ($quote->getSubtotalWithDiscount() ?: $subtotal);

        return [
            'currency' => (string) $quote->getQuoteCurrencyCode(),
            'total' => $this->money((float) $quote->getGrandTotal()),
            'subtotal' => $this->money($subtotal),
            'discount_total' => $this->money(max(0, $subtotal - $subtotalWithDiscount)),
            'shipping_total' => $this->money(null !== $address ? (float) $address->getShippingAmount() : 0),
            'tax_total' => $this->money(null !== $address ? (float) $address->getTaxAmount() : 0),
            'coupon_codes' => $couponCodes,
            'item_count' => (float) $quote->getItemsQty(),
            'items' => $items,
            'recovery_url' => $this->buildRecoveryUrl($quote, $items, $couponCodes),
        ];
    }

    private function buildRecoveryUrl(Quote $quote, array $items, array $couponCodes): ?string
    {
        $secret = $this->config->getConnectionSecret();
        if ('' === $secret || 0 === \count($items)) {
            return null;
        }

        $payloadItems = [];
        foreach ($items as $item) {
            $payloadItems[] = [
                'id' => $item['product_id'],
                'v' => $item['variation_id'],
                'q' => (int) round((float) $item['quantity']),
            ];
        }
        $encoded = rtrim(strtr(base64_encode(
            json_encode(['items' => $payloadItems, 'c' => $couponCodes], \JSON_UNESCAPED_SLASHES) ?: ''
        ), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $secret);

        try {
            $base = rtrim((string) $this->storeManager->getStore((int) $quote->getStoreId())->getBaseUrl(), '/');
        } catch (\Throwable) {
            return null;
        }

        return $base . '/fullmetrix/cart/recover?fm_cart=' . $encoded . '&fm_cart_sig=' . $signature;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
