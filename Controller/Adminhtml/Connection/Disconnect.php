<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Adminhtml\Connection;

use Fullmetrix\Connector\Model\ConnectionManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;

class Disconnect extends Action
{
    public const ADMIN_RESOURCE = 'Fullmetrix_Connector::connection';

    public function __construct(
        Context $context,
        private readonly ConnectionManager $connectionManager,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $this->connectionManager->disconnect();
        $this->messageManager->addSuccessMessage(__('Boutique déconnectée de Fullmetrix.'));

        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('fullmetrix/connection/index');

        return $redirect;
    }
}
