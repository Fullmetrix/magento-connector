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
     * Returns the order the success page shows, as one JSON attribute the script reads, empty when there is none.
     *
     * The order is the one Magento already loaded into the checkout session for this page.
     *
     * @return string
     */
    public function getOrderJson(): string
    {
        $order = $this->getOrderData();
        if ([] === $order) {
            return '';
        }

        return (string) json_encode($order, \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Returns the order fields the script reads.
     *
     * @return array
     */
    public function getOrderData(): array
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
                'id' => (string) $orderId,
                'number' => (string) $order->getIncrementId(),
                'total' => number_format((float) $order->getGrandTotal(), 2, '.', ''),
                'currency' => (string) $order->getOrderCurrencyCode(),
            ];
        } catch (\Throwable) {
            return [];
        }
    }
}
