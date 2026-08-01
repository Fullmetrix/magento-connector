<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Cron;

use Fullmetrix\Connector\Model\WebhookQueue;

class FlushWebhooks
{
    public function __construct(private readonly WebhookQueue $webhookQueue)
    {
    }

    public function execute(): void
    {
        $this->webhookQueue->flush();
    }
}
