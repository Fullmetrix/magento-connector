<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Block;

use Fullmetrix\Connector\Model\ApiClient;
use Fullmetrix\Connector\Model\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Tracker extends Template
{
    /**
     * @param Context $context
     * @param Config $config
     * @param ApiClient $apiClient
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Tells whether the tracker tag is rendered, from local settings only.
     *
     * @return bool
     */
    public function shouldRender(): bool
    {
        try {
            return $this->config->isActive() && $this->apiClient->isTrackerEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Returns the tracker url.
     *
     * @return string
     */
    public function getTrackerUrl(): string
    {
        $cacheBucket = (int) floor(time() / 300);

        return $this->config->getAppOrigin() . '/t.js?ver=' . Config::VERSION . '.' . $cacheBucket;
    }

    /**
     * Returns the connection code.
     *
     * @return string
     */
    public function getConnectionCode(): string
    {
        return $this->config->getConnectionCode();
    }
}
