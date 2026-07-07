<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Api;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\EntityPaginator;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

class Stream extends AbstractApiAction implements HttpGetActionInterface
{
    public function execute(): ResultInterface
    {
        if (!$this->verifier->verify($this->request)) {
            return $this->unauthorized();
        }

        $entity = (string) $this->request->getParam('entity', '');
        if ('' !== $entity && !$this->paginator->isSupported($entity)) {
            return $this->json(['success' => false, 'error' => 'unknown_entity'], 400);
        }

        $since = $this->parseSince();
        $entities = '' !== $entity ? [$entity] : EntityPaginator::ENTITIES;

        $this->sendStreamHeaders();

        $this->emit([
            'type' => 'meta',
            'entity' => '' !== $entity ? $entity : null,
            'started_at' => $this->isoNow(),
            'mode' => 'fast_stream',
            'version' => Config::VERSION,
        ]);

        $totalCount = 0;
        foreach ($entities as $currentEntity) {
            $count = 0;
            foreach ($this->paginator->streamKeyset($currentEntity, 1000, $since) as $row) {
                $payload = $this->serializeRow($currentEntity, $row);
                if (null === $payload) {
                    continue;
                }
                $this->emit(['type' => $this->lineType($currentEntity), 'data' => $payload]);
                ++$count;
            }
            $this->emit(['type' => 'entity_complete', 'entity' => $currentEntity, 'count' => $count]);
            $totalCount += $count;
        }

        $this->emit(['type' => 'done', 'completed_at' => $this->isoNow(), 'count' => $totalCount]);

        exit(0);
    }

    private $outputHandle = null;

    private function sendStreamHeaders(): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/x-ndjson');
            header('X-Accel-Buffering: no');
            header('Cache-Control: no-cache');
        }
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        set_time_limit(0);
        ignore_user_abort(false);
        $this->outputHandle = fopen('php://output', 'wb');
    }

    private function emit(array $row): void
    {
        if (null === $this->outputHandle) {
            return;
        }
        fwrite($this->outputHandle, (json_encode($row, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}') . "\n");
        flush();
    }
}
