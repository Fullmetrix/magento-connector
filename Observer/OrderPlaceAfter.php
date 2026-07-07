<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\HmacSigner;
use Fullmetrix\Connector\Model\HttpClient;
use Fullmetrix\Connector\Model\TrackingQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Sales\Model\Order;

class OrderPlaceAfter implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly HmacSigner $signer,
        private readonly HttpClient $httpClient,
        private readonly TrackingQueue $trackingQueue,
        private readonly SubscriberFactory $subscriberFactory,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || !$this->config->isActive()) {
            return;
        }

        $email = (string) $order->getCustomerEmail();
        if ('' === $email) {
            return;
        }

        try {
            $this->trackingQueue->enqueue('identify', [], [
                'email' => $email,
                'first_name' => (string) $order->getCustomerFirstname(),
                'last_name' => (string) $order->getCustomerLastname(),
                'customer_id' => $order->getCustomerId() ? (int) $order->getCustomerId() : null,
                'identified_at' => (int) round(microtime(true) * 1000),
            ]);
        } catch (\Throwable) {
        }

        try {
            $subscribed = false;
            $subscriber = $this->subscriberFactory->create()->loadBySubscriberEmail(
                $email,
                (int) $order->getStore()->getWebsiteId()
            );
            if ($subscriber->getId()) {
                $subscribed = $subscriber->isSubscribed();
            }
            if (!$subscribed) {
                return;
            }

            $billing = $order->getBillingAddress();
            $body = json_encode([
                'key' => $this->config->getConnectionCode(),
                'email' => $email,
                'phone' => null !== $billing ? (string) $billing->getTelephone() : '',
                'consent' => true,
                'channels' => ['email'],
                'pageUrl' => '',
            ], \JSON_UNESCAPED_SLASHES);
            if (false === $body) {
                return;
            }
            $this->httpClient->postFireAndForget(
                $this->config->getAppOrigin() . '/api/checkout-consent',
                $body,
                $this->signer->buildHeaders($body)
            );
        } catch (\Throwable) {
        }
    }
}
