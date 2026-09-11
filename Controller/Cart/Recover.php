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
    private const CART_PARAM = 'fm_cart_id';
    private const MAX_ITEMS = 25;
    private const LINK_PARAMS = ['fm_cart_id', 'fm_cart', 'fm_cart_sig'];

    /**
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param Config $config
     * @param CheckoutCart $cart
     * @param ProductRepositoryInterface $productRepository
     * @param StoreScope $storeScope
     * @param StoreSettingsProvider $storeSettings
     * @param Configurable $configurableType
     * @param \Fullmetrix\Connector\Model\CartLinkResolver $linkResolver
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
        private readonly \Fullmetrix\Connector\Model\CartLinkResolver $linkResolver,
    ) {
    }

    /**
     * Runs the controller action.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $linkId = (string) $this->request->getParam(self::CART_PARAM, '');
        $payload = (string) $this->request->getParam('fm_cart', '');
        $signature = (string) $this->request->getParam('fm_cart_sig', '');

        if (!$this->config->isRegistered()) {
            return $this->buildRedirect('cart');
        }
        if ('' === $linkId && ('' === $payload || '' === $signature)) {
            return $this->buildRedirect('cart');
        }
        if ((int) $this->cart->getQuote()->getStoreId() !== $this->storeSettings->getStoreId()) {
            return $this->buildRedirect('cart');
        }

        $decoded = '' !== $linkId
            ? $this->linkResolver->resolve($linkId)
            : $this->decodePayload($payload, $signature);

        if (!\is_array($decoded)) {
            return $this->buildRedirect('cart');
        }

        $existing = [];
        foreach ($this->cart->getQuote()->getAllVisibleItems() as $quoteItem) {
            $existing[$this->quoteItemKey($quoteItem)] = true;
        }

        $items = \is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
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
                $key = $this->itemKey($productId, $attributes);
                if (isset($existing[$key])) {
                    continue;
                }
                if (\count($attributes) > 0) {
                    $params['super_attribute'] = $attributes;
                }

                try {
                    $this->cart->addProduct($product, $params);
                } catch (\Throwable) {
                    if (0 === \count($attributes)) {
                        continue;
                    }
                    $parentKey = $this->itemKey($productId, []);
                    if (isset($existing[$parentKey])) {
                        continue;
                    }
                    $this->cart->addProduct($product, ['qty' => $quantity]);
                    $existing[$parentKey] = true;

                    continue;
                }

                $existing[$key] = true;
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

        $target = ('checkout' === ($decoded['target'] ?? 'cart')) ? 'checkout' : 'cart';

        return $this->buildRedirect($target);
    }

    /**
     * Redirect keeping the incoming query, minus the recovery parameters.
     *
     * @param string $target
     * @return Redirect
     */
    private function buildRedirect(string $target): Redirect
    {
        $redirect = $this->redirectFactory->create();

        $params = [];
        $query = $this->request->getParams();
        if (\is_array($query)) {
            foreach ($query as $key => $value) {
                if (\in_array($key, self::LINK_PARAMS, true)) {
                    continue;
                }
                if (\is_string($key) && \is_scalar($value)) {
                    $params[$key] = (string) $value;
                }
            }
        }

        $path = 'checkout' === $target ? 'checkout/index' : 'checkout/cart';
        $redirect->setPath($path, \count($params) > 0 ? ['_query' => $params] : []);

        return $redirect;
    }

    /**
     * Decodes the legacy signed payload.
     *
     * @param string $payload
     * @param string $signature
     * @return array|null
     */
    private function decodePayload(string $payload, string $signature): ?array
    {
        $expected = hash_hmac('sha256', $payload, $this->config->getConnectionSecret());
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $decoded = json_decode(
            // The connector needs the raw call here, the Magento wrapper does not cover it.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            base64_decode(strtr($payload, '-_', '+/')) ?: '',
            true
        );

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Identity of a cart line: the product plus its chosen options.
     *
     * @param int $productId
     * @param array $attributes
     * @return string
     */
    private function itemKey(int $productId, array $attributes): string
    {
        ksort($attributes);

        return $productId . ':' . implode(',', array_map(
            static fn ($attributeId, $optionId): string => $attributeId . '=' . $optionId,
            array_keys($attributes),
            $attributes
        ));
    }

    /**
     * Identity of an existing quote item, comparable with itemKey().
     *
     * @param \Magento\Quote\Model\Quote\Item $quoteItem
     * @return string
     */
    private function quoteItemKey(\Magento\Quote\Model\Quote\Item $quoteItem): string
    {
        $productId = (int) $quoteItem->getProductId();
        $parent = $quoteItem->getProduct();
        $child = $quoteItem->getOptionByCode('simple_product')?->getProduct();

        if (!$parent instanceof Product || !$child instanceof Product) {
            return $this->itemKey($productId, []);
        }

        return $this->itemKey($productId, $this->attributesFromChild($parent, $child));
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

            return $this->attributesFromChild($parent, $child);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Maps a configurable child onto its attribute/option pairs.
     *
     * @param Product $parent
     * @param Product $child
     * @return array
     */
    private function attributesFromChild(Product $parent, Product $child): array
    {
        try {
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
