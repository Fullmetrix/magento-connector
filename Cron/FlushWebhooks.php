<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Cron;

use Fullmetrix\Connector\Model\WebhookQueue;

class FlushWebhooks
{
    /**
     * @param WebhookQueue $webhookQueue
     */
    public function __construct(private readonly WebhookQueue $webhookQueue)
    {
    }

    /**
     * Runs the controller action.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->webhookQueue->flush();
    }
}
