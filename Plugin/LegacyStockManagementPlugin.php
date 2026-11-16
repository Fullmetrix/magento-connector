<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Plugin;

use Fullmetrix\Connector\Model\MsiStock;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\CatalogInventory\Api\StockManagementInterface;
use Magento\Framework\DB\Adapter\DeadlockException;

class LegacyStockManagementPlugin
{
    /**
     * @param WebhookQueue $webhookQueue
     * @param MsiStock $msiStock
     */
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly MsiStock $msiStock,
    ) {
    }

    /**
     * Records the products whose stock an order took, on stores that run without Magento Inventory.
     *
     * @param StockManagementInterface $subject
     * @param mixed $result
     * @param mixed $items
     * @param mixed $websiteId
     * @return mixed
     */
    public function afterRegisterProductsSale(
        StockManagementInterface $subject,
        mixed $result,
        mixed $items = [],
        mixed $websiteId = null,
    ): mixed {
        $this->record(\is_array($items) ? array_keys($items) : []);

        return $result;
    }

    /**
     * Records the products whose stock a cancelled sale gave back.
     *
     * @param StockManagementInterface $subject
     * @param mixed $result
     * @param mixed $items
     * @param mixed $websiteId
     * @return mixed
     */
    public function afterRevertProductsSale(
        StockManagementInterface $subject,
        mixed $result,
        mixed $items = [],
        mixed $websiteId = null,
    ): mixed {
        $this->record(\is_array($items) ? array_keys($items) : []);

        return $result;
    }

    /**
     * Records the product whose stock a refund returned.
     *
     * @param StockManagementInterface $subject
     * @param mixed $result
     * @param mixed $productId
     * @param mixed $qty
     * @param mixed $scopeId
     * @return mixed
     */
    public function afterBackItemQty(
        StockManagementInterface $subject,
        mixed $result,
        mixed $productId = null,
        mixed $qty = null,
        mixed $scopeId = null,
    ): mixed {
        $this->record([$productId]);

        return $result;
    }

    /**
     * Queues the products in one statement, Magento Inventory stores are covered by its own plugins.
     *
     * @param array $productIds
     * @return void
     */
    private function record(array $productIds): void
    {
        try {
            if ($this->msiStock->isAvailable()) {
                return;
            }
            $ids = [];
            foreach ($productIds as $productId) {
                if (is_numeric($productId) && (int) $productId > 0) {
                    $ids[(int) $productId] = (int) $productId;
                }
            }
            if ([] !== $ids) {
                $this->webhookQueue->enqueueMany('product', array_values($ids), 'product.updated');
            }
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
