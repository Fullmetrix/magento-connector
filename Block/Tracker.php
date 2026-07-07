<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Block;

use Fullmetrix\Connector\Model\ApiClient;
use Fullmetrix\Connector\Model\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Tracker extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function shouldRender(): bool
    {
        return $this->config->isActive() && $this->apiClient->isTrackerEnabled();
    }

    public function getTrackerUrl(): string
    {
        $cacheBucket = (int) floor(time() / 300);

        return $this->config->getAppOrigin() . '/t.js?ver=' . Config::VERSION . '.' . $cacheBucket;
    }

    public function getConnectionCode(): string
    {
        return $this->config->getConnectionCode();
    }
}
