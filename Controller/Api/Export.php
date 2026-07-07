<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Api;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\EntityPaginator;
use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\HmacRequestVerifier;
use Fullmetrix\Connector\Model\StoreSettingsProvider;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

class Export extends AbstractApiAction implements HttpGetActionInterface
{
    private const MAX_PER_PAGE = 500;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        HmacRequestVerifier $verifier,
        EntityPaginator $paginator,
        EntitySerializer $serializer,
        Config $config,
        private readonly StoreSettingsProvider $storeSettings,
    ) {
        parent::__construct($request, $jsonFactory, $verifier, $paginator, $serializer, $config);
    }

    public function execute(): ResultInterface
    {
        if (!$this->verifier->verify($this->request)) {
            return $this->unauthorized();
        }

        $type = (string) $this->request->getParam('type', 'orders');

        if ('settings' === $type) {
            return $this->json([
                'success' => true,
                'settings' => $this->storeSettings->getSettings(),
            ]);
        }

        if (!$this->paginator->isSupported($type)) {
            return $this->json(['success' => false, 'error' => 'unknown_entity'], 400);
        }

        $page = max(1, (int) $this->request->getParam('page', 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) $this->request->getParam('per_page', 100)));
        $since = (string) $this->request->getParam('since', '');
        $since = '' !== $since ? $since : null;

        $total = $this->paginator->countByEntity($type, $since);
        $rows = [];
        $skip = ($page - 1) * $perPage;
        $index = 0;
        foreach ($this->paginator->streamKeyset($type, min(1000, max($perPage, 100)), $since) as $row) {
            if ($index++ < $skip) {
                continue;
            }
            $payload = $this->serializeRow($type, $row);
            if (null !== $payload) {
                $rows[] = $payload;
            }
            if (\count($rows) >= $perPage) {
                break;
            }
        }

        return $this->json([
            'success' => true,
            'data' => $rows,
            $type => $rows,
            'meta' => [
                'total' => $total,
                'currentPage' => $page,
                'perPage' => $perPage,
                'totalPages' => (int) ceil($total / $perPage),
                'mode' => 'fast',
                'pluginVersion' => Config::VERSION,
                'exportedAt' => $this->isoNow(),
            ],
        ]);
    }

}
