<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Block;

use Fullmetrix\Connector\Model\ApiClient;
use Fullmetrix\Connector\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class OrderReference extends Template
{
    /**
     * @param Context $context
     * @param Config $config
     * @param ApiClient $apiClient
     * @param CheckoutSession $checkoutSession
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly CheckoutSession $checkoutSession,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Returns the order attributes as escaped markup for the success page template.
     *
     * @param \Magento\Framework\Escaper $escaper
     * @return string
     */
    public function getOrderAttributesHtml($escaper): string
    {
        $html = '';
        foreach ($this->getOrderAttributes() as $name => $value) {
            $html .= ' ' . $escaper->escapeHtmlAttr($name)
                . '="' . $escaper->escapeHtmlAttr($value) . '"';
        }

        return $html;
    }

    /**
     * Returns the order the success page shows, as attributes the tracker reads.
     *
     * The order is the one Magento already loaded into the checkout session for this page.
     *
     * @return array
     */
    public function getOrderAttributes(): array
    {
        try {
            if (!$this->config->isActive() || !$this->apiClient->isTrackerEnabled()) {
                return [];
            }
            $order = $this->checkoutSession->getLastRealOrder();
            $orderId = $order ? (int) $order->getEntityId() : 0;
            if ($orderId <= 0) {
                return [];
            }

            return [
                'data-fm-order-id' => (string) $orderId,
                'data-fm-order-number' => (string) $order->getIncrementId(),
                'data-fm-order-total' => number_format((float) $order->getGrandTotal(), 2, '.', ''),
                'data-fm-order-currency' => (string) $order->getOrderCurrencyCode(),
            ];
        } catch (\Throwable) {
            return [];
        }
    }
}
