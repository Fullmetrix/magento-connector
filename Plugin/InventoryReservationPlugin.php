<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Plugin;

use Fullmetrix\Connector\Model\StoreSettingsProvider;
use Fullmetrix\Connector\Model\SkuList;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\InventorySales\Model\PlaceReservationsForSalesEvent;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\Framework\DB\Adapter\DeadlockException;

class InventoryReservationPlugin
{
    /**
     * @param WebhookQueue $webhookQueue
     * @param StoreSettingsProvider $storeSettings
     */
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly StoreSettingsProvider $storeSettings,
    ) {
    }

    /**
     * Records the SKUs whose salable quantity changed, the cron reloads and sends them.
     *
     * @param PlaceReservationsForSalesEvent $subject
     * @param mixed $result
     * @param array $items
     * @param SalesChannelInterface $salesChannel
     * @return mixed
     */
    public function afterExecute(
        PlaceReservationsForSalesEvent $subject,
        mixed $result,
        array $items,
        SalesChannelInterface $salesChannel,
    ): mixed {
        try {
            if ((string) $salesChannel->getCode() === $this->storeSettings->getWebsiteCode()) {
                $this->webhookQueue->enqueueMany('product', SkuList::fromItems($items), 'product.updated');
            }
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }

        return $result;
    }
}
