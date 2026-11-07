<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Cron;

use Fullmetrix\Connector\Model\TrackerScriptSync;

class SyncTrackerScript
{
    /**
     * @param TrackerScriptSync $sync
     */
    public function __construct(
        private readonly TrackerScriptSync $sync,
    ) {
    }

    /**
     * Refreshes the tracker file served from the store, outside any shopper request.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->sync->run();
        // The storefront keeps the last valid tag, the next run retries.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
