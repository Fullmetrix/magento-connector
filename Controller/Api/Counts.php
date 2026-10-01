<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Api;

use Fullmetrix\Connector\Model\EntityPaginator;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

class Counts extends AbstractApiAction implements HttpGetActionInterface
{
    /**
     * Runs the controller action.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $denied = $this->guard('counts');
        if (null !== $denied) {
            return $denied;
        }

        $counts = [];
        foreach (EntityPaginator::ENTITIES as $entity) {
            $counts[$entity] = $this->paginator->countByEntity($entity);
        }

        return $this->json(['success' => true, 'counts' => $counts]);
    }
}
