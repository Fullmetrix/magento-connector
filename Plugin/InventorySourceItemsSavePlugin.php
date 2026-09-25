<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Plugin;

use Fullmetrix\Connector\Model\SkuList;
use Fullmetrix\Connector\Model\WebhookQueue;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\Framework\DB\Adapter\DeadlockException;

class InventorySourceItemsSavePlugin
{
    /**
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(private readonly WebhookQueue $webhookQueue)
    {
    }

    /**
     * Records the SKUs whose stock was written, in one statement whatever the size of the batch.
     *
     * @param SourceItemsSaveInterface $subject
     * @param mixed $result
     * @param array $sourceItems
     * @return mixed
     */
    public function afterExecute(SourceItemsSaveInterface $subject, mixed $result, array $sourceItems): mixed
    {
        try {
            $this->webhookQueue->enqueueMany('product', SkuList::fromItems($sourceItems), 'product.updated');
        } catch (DeadlockException $e) {
            throw $e;
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }

        return $result;
    }
}
