<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Api;

use Fullmetrix\Connector\Model\EntityPaginator;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

class Counts extends AbstractApiAction implements HttpGetActionInterface
{
    public function execute(): ResultInterface
    {
        if (!$this->verifier->verify($this->request)) {
            return $this->unauthorized();
        }

        $counts = [];
        foreach (EntityPaginator::ENTITIES as $entity) {
            $counts[$entity] = $this->paginator->countByEntity($entity);
        }

        return $this->json(['success' => true, 'counts' => $counts]);
    }
}
