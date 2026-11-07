<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Observer;

use Fullmetrix\Connector\Model\CookieReader;
use Fullmetrix\Connector\Model\TrackingQueue;
use Fullmetrix\Connector\Model\StoreScope;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\DB\Adapter\DeadlockException;

class OrderPlaceAfter implements ObserverInterface
{
    private const SESSION_AREAS = [Area::AREA_FRONTEND, Area::AREA_WEBAPI_REST, Area::AREA_GRAPHQL];

    private const EXCLUDED_AREAS = [Area::AREA_ADMINHTML, Area::AREA_CRONTAB];

    /**
     * @param TrackingQueue $trackingQueue
     * @param StoreScope $storeScope
     * @param WebhookQueue $webhookQueue
     * @param CookieReader $cookieReader
     * @param State $appState
     * @param CustomerSession $customerSession
     */
    public function __construct(
        private readonly TrackingQueue $trackingQueue,
        private readonly StoreScope $storeScope,
        private readonly WebhookQueue $webhookQueue,
        private readonly CookieReader $cookieReader,
        private readonly State $appState,
        private readonly CustomerSession $customerSession,
    ) {
    }

    /**
     * Records who placed the order, the subscription is checked by the cron.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof Order || !$this->storeScope->includesOrder($order)) {
                return;
            }
            $email = (string) $order->getCustomerEmail();
            if ('' === $email) {
                return;
            }
            $this->trackingQueue->enqueue('identify', $this->orderContext($order), [
                'email' => $email,
                'first_name' => (string) $order->getCustomerFirstname(),
                'last_name' => (string) $order->getCustomerLastname(),
                'customer_id' => $order->getCustomerId() ? (int) $order->getCustomerId() : null,
                'identified_at' => (int) round(microtime(true) * 1000),
            ]);
            $billing = $order->getBillingAddress();
            $this->webhookQueue->enqueueOrderConsentCheck(
                $email,
                (int) $order->getStore()->getWebsiteId(),
                null !== $billing ? (string) $billing->getTelephone() : '',
                null !== $billing ? (string) $billing->getCountryId() : ''
            );
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }

    /**
     * Order reference and browser ids, only for an order placed by the shopper.
     *
     * @param Order $order
     * @return array
     */
    private function orderContext(Order $order): array
    {
        try {
            if (null === $this->cookieReader->getVisitorId()) {
                return [];
            }
            $area = (string) $this->appState->getAreaCode();
            if (\in_array($area, self::EXCLUDED_AREAS, true)) {
                return [];
            }
            if (\in_array($area, self::SESSION_AREAS, true)
                && $this->customerSession->getLoggedAsCustomerAdminId()
            ) {
                return [];
            }
            $incrementId = (string) $order->getIncrementId();
            if ('' === $incrementId) {
                return [];
            }
            $context = ['order_increment_id' => $incrementId];
            if ($order->getQuoteId()) {
                $context['quote_id'] = (int) $order->getQuoteId();
            }

            return $context + $this->cookieReader->getAdSignals();
        } catch (DeadlockException $e) {
            throw $e;
        } catch (\Throwable) {
            return [];
        }
    }
}
