<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Cart;

use Fullmetrix\Connector\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Cart as CheckoutCart;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;

class Recover implements ActionInterface, HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly Config $config,
        private readonly CheckoutCart $cart,
        private readonly ProductRepositoryInterface $productRepository,
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $redirect->setPath('checkout/cart');

        $payload = (string) $this->request->getParam('fm_cart', '');
        $signature = (string) $this->request->getParam('fm_cart_sig', '');
        if ('' === $payload || '' === $signature || !$this->config->isRegistered()) {
            return $redirect;
        }

        $expected = hash_hmac('sha256', $payload, $this->config->getConnectionSecret());
        if (!hash_equals($expected, $signature)) {
            return $redirect;
        }

        $decoded = json_decode(
            base64_decode(strtr($payload, '-_', '+/')) ?: '',
            true
        );
        if (!\is_array($decoded)) {
            return $redirect;
        }

        $items = \is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $productId = (int) ($item['v'] ?? 0) > 0 ? (int) $item['v'] : (int) ($item['id'] ?? 0);
            $quantity = max(1, (int) ($item['q'] ?? 1));
            if ($productId <= 0) {
                continue;
            }
            try {
                $product = $this->productRepository->getById($productId);
                $this->cart->addProduct($product, ['qty' => $quantity]);
            } catch (\Throwable) {
            }
        }

        $coupons = \is_array($decoded['c'] ?? null) ? $decoded['c'] : [];
        if (\count($coupons) > 0 && \is_string($coupons[0]) && '' !== $coupons[0]) {
            try {
                $this->cart->getQuote()->setCouponCode($coupons[0]);
            } catch (\Throwable) {
            }
        }

        try {
            $this->cart->save();
        } catch (\Throwable) {
        }

        return $redirect;
    }
}
