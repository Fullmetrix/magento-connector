<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Adminhtml\Connection;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Fullmetrix_Connector::connection';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Fullmetrix_Connector::connection');
        $page->getConfig()->getTitle()->prepend('Fullmetrix');

        return $page;
    }
}
