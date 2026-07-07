<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Block\Adminhtml;

use Fullmetrix\Connector\Model\Config;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Connection extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function isRegistered(): bool
    {
        return $this->config->isRegistered();
    }

    public function getConnectionCode(): string
    {
        return $this->config->getConnectionCode();
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('fullmetrix/connection/save');
    }

    public function getDisconnectUrl(): string
    {
        return $this->getUrl('fullmetrix/connection/disconnect');
    }

    public function getAppUrl(): string
    {
        return $this->config->getAppOrigin();
    }
}
