<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Adminhtml\Connection;

use Fullmetrix\Connector\Model\ConnectionManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;

class Save extends Action
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
        $code = (string) $this->getRequest()->getParam('connection_code', '');
        $result = $this->connectionManager->connect($code);

        if ($result['success']) {
            $this->messageManager->addSuccessMessage(__('Boutique connectée à Fullmetrix. La synchronisation démarre automatiquement.'));
        } else {
            $this->messageManager->addErrorMessage(__('Échec de la connexion : %1', $result['error'] ?? 'unknown'));
        }

        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('fullmetrix/connection/index');

        return $redirect;
    }
}
