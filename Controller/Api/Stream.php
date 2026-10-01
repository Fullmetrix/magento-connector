<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Api;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\EntityPaginator;
use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\HmacRequestVerifier;
use Fullmetrix\Connector\Model\HttpClient;
use Fullmetrix\Connector\Model\WebhookDispatcher;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * Streams every supported entity to Fullmetrix as newline delimited JSON.
 */
class Stream extends AbstractApiAction implements HttpGetActionInterface
{
    private const MAX_CONSECUTIVE_FAILURES = 3;

    /**
     * Output stream the NDJSON lines are written to.
     *
     * @var resource|null
     */
    private $outputHandle = null;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param HmacRequestVerifier $verifier
     * @param EntityPaginator $paginator
     * @param EntitySerializer $serializer
     * @param Config $config
     * @param WebhookDispatcher $dispatcher
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        HmacRequestVerifier $verifier,
        EntityPaginator $paginator,
        EntitySerializer $serializer,
        Config $config,
        private readonly WebhookDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($request, $jsonFactory, $verifier, $paginator, $serializer, $config);
    }

    /**
     * Streams the requested entity, or every entity when none is given.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $denied = $this->guard('stream');
        if (null !== $denied) {
            return $denied;
        }

        $entity = (string) $this->request->getParam('entity', '');
        $fromId = max(0, (int) $this->request->getParam('from_id', 0));
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
            'supports_from_id' => true,
        ]);

        $totalCount = 0;
        $counts = [];
        $failed = false;
        foreach ($entities as $currentEntity) {
            $count = 0;
            $lastCursor = $fromId;
            $failures = 0;
            $prefetch = function (array $ids) use ($currentEntity): void {
                $this->serializer->releaseLoadedEntities();
                $this->serializer->prefetchRelated($currentEntity, $ids);
            };
            while (true) {
                try {
                    $rows = $this->paginator->streamKeyset($currentEntity, 1000, $since, $prefetch, $lastCursor);
                    foreach ($rows as $row) {
                        $payload = $this->serializeRow($currentEntity, $row);
                        if (null === $payload) {
                            continue;
                        }
                        $cursor = $this->paginator->cursorValue($currentEntity, $row);
                        $line = ['type' => $this->lineType($currentEntity), '_cursor' => $cursor, 'data' => $payload];
                        $this->emit($line);
                        $lastCursor = $cursor ?? $lastCursor;
                        $failures = 0;
                        ++$count;
                    }
                    break;
                } catch (\Throwable $e) {
                    $this->logger->critical($e);
                    if (++$failures >= self::MAX_CONSECUTIVE_FAILURES) {
                        $this->emit([
                            'type' => 'fatal',
                            'entity' => $currentEntity,
                            'reason' => 'query_failed',
                            'last_id' => (int) $lastCursor,
                            'sent' => $count,
                        ]);
                        $failed = true;
                        break 2;
                    }
                    $this->pauseAfterFailure($failures);
                }
            }
            $this->emit(['type' => 'entity_complete', 'entity' => $currentEntity, 'count' => $count]);
            $counts[$currentEntity] = $count;
            $totalCount += $count;
        }

        if (!$failed) {
            $this->config->markSyncCompleted($counts);
            $this->emit(['type' => 'done', 'completed_at' => $this->isoNow(), 'count' => $totalCount]);
        }

        $this->flushQueueIfCronStalled();

        // The body is already written and flushed, letting Magento render a response would corrupt the stream.
        // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
        exit(0);
    }

    /**
     * Waits before reading the same page again.
     *
     * @param int $failures
     * @return void
     */
    protected function pauseAfterFailure(int $failures): void
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        sleep(2 * $failures);
    }

    /**
     * Delivers the queued events from this signed request when the Magento cron no longer runs.
     *
     * @return void
     */
    private function flushQueueIfCronStalled(): void
    {
        try {
            if (!$this->dispatcher->isStalled()) {
                return;
            }
            HttpClient::finishResponse();
            $this->dispatcher->flush(WebhookDispatcher::FALLBACK_BUDGET_SECONDS);
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
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
