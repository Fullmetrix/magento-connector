<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Cron;

use Fullmetrix\Connector\Model\ApiClient;
use Fullmetrix\Connector\Model\WebhookDispatcher;

class FlushWebhooks
{
    /**
     * @param WebhookDispatcher $dispatcher
     * @param ApiClient $apiClient
     */
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly ApiClient $apiClient,
    ) {
    }

    /**
     * Sends the queued events and refreshes the plugin configuration, outside any shopper request.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->dispatcher->flush();
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
        try {
            $this->apiClient->fetchPluginConfig();
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
