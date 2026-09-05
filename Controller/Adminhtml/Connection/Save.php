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

    /**
     * @param Context $context
     * @param ConnectionManager $connectionManager
     */
    public function __construct(
        Context $context,
        private readonly ConnectionManager $connectionManager,
    ) {
        parent::__construct($context);
    }

    /**
     * Runs the controller action.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $code = (string) $this->getRequest()->getParam('connection_code', '');
        $storeId = (int) $this->getRequest()->getParam('store_id', 0);
        $result = $this->connectionManager->connect($code, $storeId > 0 ? $storeId : null);

        if ($result['success']) {
            $this->messageManager->addSuccessMessage(
                __('Store connected to Fullmetrix. The first sync starts automatically.')
            );
        } else {
            $this->messageManager->addErrorMessage(__('Échec de la connexion : %1', $result['error'] ?? 'unknown'));
        }

        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('fullmetrix/connection/index');

        return $redirect;
    }
}
