<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Cart;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\StoreScope;
use Fullmetrix\Connector\Model\StoreSettingsProvider;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Checkout\Model\Cart as CheckoutCart;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;

class Recover implements ActionInterface, HttpGetActionInterface
{
    /**
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param Config $config
     * @param CheckoutCart $cart
     * @param ProductRepositoryInterface $productRepository
     * @param StoreScope $storeScope
     * @param StoreSettingsProvider $storeSettings
     * @param Configurable $configurableType
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly Config $config,
        private readonly CheckoutCart $cart,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreScope $storeScope,
        private readonly StoreSettingsProvider $storeSettings,
        private readonly Configurable $configurableType,
    ) {
    }

    /**
     * Runs the controller action.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $redirect->setPath('checkout/cart');

        $payload = (string) $this->request->getParam('fm_cart', '');
        $signature = (string) $this->request->getParam('fm_cart_sig', '');
        if ('' === $payload || '' === $signature || !$this->config->isRegistered()) {
            return $redirect;
        }
        if ((int) $this->cart->getQuote()->getStoreId() !== $this->storeSettings->getStoreId()) {
            return $redirect;
        }

        $expected = hash_hmac('sha256', $payload, $this->config->getConnectionSecret());
        if (!hash_equals($expected, $signature)) {
            return $redirect;
        }

        $decoded = json_decode(
            // The connector needs the raw call here, the Magento wrapper does not cover it.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            base64_decode(strtr($payload, '-_', '+/')) ?: '',
            true
        );
        if (!\is_array($decoded)) {
            return $redirect;
        }

        $items = \is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        foreach (array_slice($items, 0, 100) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $productId = (int) ($item['id'] ?? 0);
            $quantity = min(999, max(1, (int) ($item['q'] ?? 1)));
            if ($productId <= 0) {
                continue;
            }
            try {
                $product = $this->productRepository->getById(
                    $productId,
                    false,
                    $this->storeSettings->getStoreId(),
                    true
                );
                if (!$product instanceof Product || !$this->storeScope->includesProduct($product)) {
                    continue;
                }
                $params = ['qty' => $quantity];
                $rawAttributes = \is_array($item['a'] ?? null) ? $item['a'] : [];
                $attributes = [];
                foreach ($rawAttributes as $attributeId => $optionId) {
                    if ((int) $attributeId > 0 && (int) $optionId > 0) {
                        $attributes[(int) $attributeId] = (int) $optionId;
                    }
                }
                if (0 === \count($attributes) && (int) ($item['v'] ?? 0) > 0) {
                    $attributes = $this->attributesForLegacyVariation($product, (int) $item['v']);
                }
                if (\count($attributes) > 0) {
                    $params['super_attribute'] = $attributes;
                }
                $this->cart->addProduct($product, $params);
            // The failure is optional data, the caller keeps going.
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            } catch (\Throwable) {
            }
        }

        $coupons = \is_array($decoded['c'] ?? null) ? $decoded['c'] : [];
        if (\count($coupons) > 0 && \is_string($coupons[0]) && '' !== $coupons[0]) {
            try {
                $this->cart->getQuote()->setCouponCode($coupons[0]);
            // The failure is optional data, the caller keeps going.
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            } catch (\Throwable) {
            }
        }

        try {
            $this->cart->save();
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }

        return $redirect;
    }

    /**
     * Attributes for legacy variation.
     *
     * @param Product $parent
     * @param int $variationId
     * @return array
     */
    private function attributesForLegacyVariation(Product $parent, int $variationId): array
    {
        try {
            $child = $this->productRepository->getById(
                $variationId,
                false,
                $this->storeSettings->getStoreId(),
                true
            );
            if (!$child instanceof Product || !$this->storeScope->includesProduct($child)) {
                return [];
            }
            $attributes = [];
            foreach ($this->configurableType->getConfigurableAttributes($parent) as $attribute) {
                $productAttribute = $attribute->getProductAttribute();
                if (null === $productAttribute) {
                    continue;
                }
                $attributeId = (int) $productAttribute->getAttributeId();
                $optionId = (int) $child->getData((string) $productAttribute->getAttributeCode());
                if ($attributeId > 0 && $optionId > 0) {
                    $attributes[$attributeId] = $optionId;
                }
            }

            return $attributes;
        } catch (\Throwable) {
            return [];
        }
    }
}
