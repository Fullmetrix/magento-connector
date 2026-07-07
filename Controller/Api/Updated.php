<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Api;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

class Updated extends AbstractApiAction implements HttpGetActionInterface
{
    public function execute(): ResultInterface
    {
        if (!$this->verifier->verify($this->request)) {
            return $this->unauthorized();
        }

        $type = (string) $this->request->getParam('type', 'orders');
        if (!$this->paginator->isSupported($type)) {
            return $this->json(['success' => false, 'error' => 'unknown_entity'], 400);
        }

        $days = max(0, (int) $this->request->getParam('days', 30));
        $hours = max(0, (int) $this->request->getParam('hours', 0));
        $limit = max(1, (int) $this->request->getParam('limit', 200000));
        $offset = max(0, (int) $this->request->getParam('offset', 0));

        $items = $this->paginator->recentlyUpdated($type, $days, $hours, $limit, $offset);

        return $this->json([
            'success' => true,
            'type' => $type,
            'count' => \count($items),
            'items' => $items,
        ]);
    }
}
