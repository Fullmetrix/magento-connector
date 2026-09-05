<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Api;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\EntityPaginator;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Streams every supported entity to Fullmetrix as newline delimited JSON.
 */
class Stream extends AbstractApiAction implements HttpGetActionInterface
{
    /**
     * Output stream the NDJSON lines are written to.
     *
     * @var resource|null
     */
    private $outputHandle = null;

    /**
     * Streams the requested entity, or every entity when none is given.
     *
     * @return ResultInterface
     */
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

        $this->config->markSyncStarted(null === $since ? 'bulk' : 'incremental');

        $this->sendStreamHeaders();

        $this->emit([
            'type' => 'meta',
            'entity' => '' !== $entity ? $entity : null,
            'started_at' => $this->isoNow(),
            'mode' => 'fast_stream',
            'version' => Config::VERSION,
        ]);

        $totalCount = 0;
        $counts = [];
        foreach ($entities as $currentEntity) {
            $count = 0;
            $prefetch = function (array $ids) use ($currentEntity): void {
                $this->serializer->prefetchRelated($currentEntity, $ids);
            };
            foreach ($this->paginator->streamKeyset($currentEntity, 1000, $since, $prefetch) as $row) {
                $payload = $this->serializeRow($currentEntity, $row);
                if (null === $payload) {
                    continue;
                }
                $this->emit(['type' => $this->lineType($currentEntity), 'data' => $payload]);
                ++$count;
            }
            $this->emit(['type' => 'entity_complete', 'entity' => $currentEntity, 'count' => $count]);
            $counts[$currentEntity] = $count;
            $totalCount += $count;
        }

        $this->config->markSyncCompleted($counts);

        $this->emit(['type' => 'done', 'completed_at' => $this->isoNow(), 'count' => $totalCount]);

        // The body is already written and flushed, letting Magento render a response would corrupt the stream.
        // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
        exit(0);
    }

    /**
     * Opens the raw output stream and disables every layer of buffering.
     *
     * The response object is bypassed on purpose: it buffers the whole body in memory,
     * which defeats streaming exports of several hundred thousand rows.
     *
     * @return void
     */
    private function sendStreamHeaders(): void
    {
        if (!headers_sent()) {
            // phpcs:disable Magento2.Functions.DiscouragedFunction
            header('Content-Type: application/x-ndjson');
            header('X-Accel-Buffering: no');
            header('Cache-Control: no-cache');
            // phpcs:enable Magento2.Functions.DiscouragedFunction
        }
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        set_time_limit(0);
        ignore_user_abort(false);
        $this->outputHandle = fopen('php://output', 'wb');
        // phpcs:enable Magento2.Functions.DiscouragedFunction
    }

    /**
     * Writes a single NDJSON line and flushes it to the client.
     *
     * @param array $row
     * @return void
     */
    private function emit(array $row): void
    {
        if (null === $this->outputHandle) {
            return;
        }

        $encoded = json_encode($row, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        fwrite($this->outputHandle, $encoded . "\n");
        flush();
    }
}
