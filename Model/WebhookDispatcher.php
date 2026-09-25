<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;

class WebhookDispatcher
{
    public const CRON_BUDGET_SECONDS = 30;
    public const FALLBACK_BUDGET_SECONDS = 20;

    private const LOCK_NAME = 'fullmetrix_webhook_queue_flush';
    private const BATCH_SIZE = 50;
    private const MAX_ROWS_PER_FLUSH = 500;
    private const MAX_PEOPLE_ROWS_PER_FLUSH = 350;
    private const STALLED_AFTER_SECONDS = 600;
    private const MAX_RETRY_DELAY_SECONDS = 3600;
    private const MAX_ATTEMPTS = 30;
    private const UNREACHABLE_RETRY_SECONDS = 60;
    private const PURGE_EVERY_SECONDS = 3600;
    private const PURGE_BATCH_SIZE = 500;
    private const PURGE_BUDGET_SECONDS = 5;
    private const CATALOG_TYPES = ['product', 'category', 'coupon'];
    private const PHASE_PEOPLE = 'people';
    private const PHASE_STOCK = 'stock';
    private const PHASE_CATALOG = 'catalog';
    private const BATCH_DONE = 'done';
    private const BATCH_OUT_OF_TIME = 'out_of_time';
    private const BATCH_UNREACHABLE = 'unreachable';

    /**
     * @param Config $config
     * @param HmacSigner $signer
     * @param HttpClient $httpClient
     * @param ResourceConnection $resourceConnection
     * @param LockManagerInterface $lockManager
     * @param WebhookPayloadResolver $resolver
     * @param WebhookQueue $queue
     */
    public function __construct(
        private readonly Config $config,
        private readonly HmacSigner $signer,
        private readonly HttpClient $httpClient,
        private readonly ResourceConnection $resourceConnection,
        private readonly LockManagerInterface $lockManager,
        private readonly WebhookPayloadResolver $resolver,
        private readonly WebhookQueue $queue,
    ) {
    }

    /**
     * Serializes and sends the pending rows, oldest first, within a time budget.
     *
     * @param int $budgetSeconds
     * @return void
     */
    public function flush(int $budgetSeconds = self::CRON_BUDGET_SECONDS): void
    {
        if (!$this->config->isActive()) {
            return;
        }
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }
        try {
            $this->purge();
            $this->flushLocked(microtime(true), max(1, $budgetSeconds));
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * Tells whether rows wait for longer than the cron would ever leave them.
     *
     * @return bool
     */
    public function isStalled(): bool
    {
        return $this->queue->stats()['waiting_seconds'] > self::STALLED_AFTER_SECONDS;
    }

    /**
     * Drops what can no longer be useful, once an hour.
     *
     * Stale visits, rows unchanged for a week and rows Fullmetrix refused thirty times go. Consents and
     * deletions, which no reconciliation can bring back, are never dropped for their refusals, only for age.
     * The ids are read without locking, then deleted by primary key in small batches, and each delete checks
     * the condition again, so a row rewritten meanwhile is kept and a hook insert never waits on the purge.
     *
     * @return void
     */
    private function purge(): void
    {
        try {
            if (time() - $this->config->getQueuePurgedAt() < self::PURGE_EVERY_SECONDS) {
                return;
            }
            $this->config->markQueuePurged();
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(WebhookQueue::TABLE);
            $condition = sprintf(
                "(entity_type = '%s' AND changed_at < UTC_TIMESTAMP() - INTERVAL 1 DAY)"
                . ' OR changed_at < UTC_TIMESTAMP() - INTERVAL 7 DAY'
                . " OR (attempts >= %d AND entity_type <> '%s' AND event <> 'deleted')",
                WebhookQueue::TYPE_TRACKING,
                self::MAX_ATTEMPTS,
                WebhookQueue::TYPE_CONSENT
            );
            $deadline = microtime(true) + self::PURGE_BUDGET_SECONDS;
            $lastQueueId = 0;
            while (microtime(true) < $deadline) {
                $ids = $connection->fetchCol(
                    $connection->select()
                        ->from($table, ['queue_id'])
                        ->where('queue_id > ?', $lastQueueId)
                        ->where($condition)
                        ->order('queue_id ASC')
                        ->limit(self::PURGE_BATCH_SIZE)
                );
                if ([] === $ids) {
                    return;
                }
                $lastQueueId = (int) end($ids);
                $connection->delete($table, [
                    'queue_id IN (?)' => array_map('intval', $ids),
                    '(' . $condition . ')',
                ]);
            }
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }

    /**
     * Flushes while holding the lock, in three passes with their own share of the budget.
     *
     * Orders and people get two thirds of the time and at most 350 rows, so the catalog always
     * keeps a share; stock changes coming from orders go before the rest of the catalog.
     *
     * @param float $start
     * @param int $budgetSeconds
     * @return void
     */
    private function flushLocked(float $start, int $budgetSeconds): void
    {
        $deadline = $start + $budgetSeconds;
        $phases = [
            [self::PHASE_PEOPLE, $start + $budgetSeconds * 2 / 3, self::MAX_PEOPLE_ROWS_PER_FLUSH],
            [self::PHASE_STOCK, $deadline, self::MAX_ROWS_PER_FLUSH],
            [self::PHASE_CATALOG, $deadline, self::MAX_ROWS_PER_FLUSH],
        ];
        $processed = 0;
        $sentKeys = [];
        foreach ($phases as [$phase, $phaseDeadline, $rowLimit]) {
            $lastQueueId = 0;
            $phaseRows = 0;
            while ($phaseRows < $rowLimit
                && $processed < self::MAX_ROWS_PER_FLUSH
                && microtime(true) < $phaseDeadline
            ) {
                $limit = min(self::BATCH_SIZE, $rowLimit - $phaseRows, self::MAX_ROWS_PER_FLUSH - $processed);
                $rows = $this->fetchBatch($phase, $lastQueueId, $limit);
                if ([] === $rows) {
                    break;
                }
                $lastQueueId = (int) end($rows)['queue_id'];
                $phaseRows += \count($rows);
                $processed += \count($rows);
                $outcome = $this->processBatch($rows, $sentKeys, $phaseDeadline);
                $this->resolver->release();
                if (self::BATCH_UNREACHABLE === $outcome) {
                    return;
                }
                if (self::BATCH_OUT_OF_TIME === $outcome) {
                    break;
                }
            }
        }
    }

    /**
     * Reads the next rows that are due for one pass.
     *
     * @param string $phase
     * @param int $afterQueueId
     * @param int $limit
     * @return array
     */
    private function fetchBatch(string $phase, int $afterQueueId, int $limit): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName(WebhookQueue::TABLE))
                ->where('queue_id > ?', $afterQueueId)
                ->where('available_at <= UTC_TIMESTAMP()')
                ->order('queue_id ASC')
                ->limit($limit);
            if (self::PHASE_PEOPLE === $phase) {
                $select->where('entity_type NOT IN (?)', self::CATALOG_TYPES);
            } elseif (self::PHASE_STOCK === $phase) {
                $select->where("entity_type = 'product' AND entity_id LIKE 'sku:%'");
            } else {
                $select->where('entity_type IN (?)', self::CATALOG_TYPES)
                    ->where("NOT (entity_type = 'product' AND entity_id LIKE 'sku:%')");
            }

            return $connection->fetchAll($select);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolves one batch, sends its tracking first, then its webhooks.
     *
     * A refused row only moves that row back; an unreachable API stops the whole flush.
     *
     * @param array $rows
     * @param array $sentKeys
     * @param float $deadline
     * @return string
     */
    private function processBatch(array $rows, array &$sentKeys, float $deadline): string
    {
        $timestampMs = (int) round(microtime(true) * 1000);
        $outcome = self::BATCH_DONE;
        $tracking = [];
        $deliveries = [];
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                $outcome = self::BATCH_OUT_OF_TIME;
                break;
            }
            try {
                $delivery = $this->resolveRow($row);
            } catch (\Throwable) {
                $this->complete($row, HttpClient::DELIVERY_REJECTED);
                continue;
            }
            if (null === $delivery) {
                $this->complete($row, HttpClient::DELIVERY_DELIVERED);
                continue;
            }
            if (WebhookPayloadResolver::KIND_TRACKING === $delivery['kind']) {
                $group = $delivery['visitor_id'] . '|' . $delivery['session_id'];
                $tracking[$group]['visitor_id'] = $delivery['visitor_id'];
                $tracking[$group]['session_id'] = $delivery['session_id'];
                $tracking[$group]['events'][] = $delivery['event'];
                $tracking[$group]['rows'][] = $row;
                continue;
            }
            $deliveries[] = [$row, $delivery];
        }
        foreach ($tracking as $group) {
            if (microtime(true) >= $deadline) {
                return self::BATCH_OUT_OF_TIME;
            }
            $result = $this->sendTracking($group);
            foreach ($group['rows'] as $row) {
                $this->complete($row, $result);
            }
            if (HttpClient::DELIVERY_UNREACHABLE === $result) {
                return self::BATCH_UNREACHABLE;
            }
        }
        foreach ($deliveries as [$row, $delivery]) {
            if (microtime(true) >= $deadline) {
                return self::BATCH_OUT_OF_TIME;
            }
            if (isset($sentKeys[$delivery['key']])) {
                $this->complete($row, HttpClient::DELIVERY_DELIVERED);
                continue;
            }
            $result = $this->send($delivery, $timestampMs);
            $this->complete($row, $result);
            if (HttpClient::DELIVERY_UNREACHABLE === $result) {
                return self::BATCH_UNREACHABLE;
            }
            if (HttpClient::DELIVERY_DELIVERED === $result) {
                $sentKeys[$delivery['key']] = true;
            }
        }

        return $outcome;
    }

    /**
     * Decodes a row and resolves it.
     *
     * Rows written by 1.4 and earlier carry the serialized entity itself, they are sent as they are.
     *
     * @param array $row
     * @return array|null
     */
    private function resolveRow(array $row): ?array
    {
        $payload = json_decode((string) $row['payload'], true);
        if (!\is_array($payload)) {
            return null;
        }
        $isCurrentFormat = WebhookQueue::FORMAT === ($payload['_fm'] ?? null);
        $data = $isCurrentFormat ? $payload['data'] : $payload;

        return $this->resolver->resolve(
            (string) $row['entity_type'],
            (string) $row['entity_id'],
            (string) $row['event'],
            \is_array($data) ? $data : null
        );
    }

    /**
     * Sends an ecommerce webhook or a consent.
     *
     * @param array $delivery
     * @param int $timestampMs
     * @return string
     */
    private function send(array $delivery, int $timestampMs): string
    {
        $isConsent = WebhookPayloadResolver::KIND_CONSENT === $delivery['kind'];
        $body = json_encode($isConsent ? [
            'key' => $this->config->getConnectionCode(),
            'email' => $delivery['data']['email'],
            'phone' => $delivery['data']['phone'],
            'country' => $delivery['data']['country'],
            'consent' => $delivery['data']['consent'],
            'channels' => ['email'],
            'pageUrl' => '',
        ] : [
            'event' => $delivery['event'],
            'entity_type' => $delivery['entity_type'],
            'data' => $delivery['data'],
            'plugin_version' => Config::VERSION,
            'timestamp' => $timestampMs,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === $body) {
            return HttpClient::DELIVERY_DELIVERED;
        }

        return $this->httpClient->deliver(
            $this->config->getAppOrigin() . ($isConsent ? '/api/checkout-consent' : '/api/webhooks/ecommerce'),
            $body,
            $this->signer->buildHeaders($body)
        );
    }

    /**
     * Sends the tracking events of one visitor session.
     *
     * @param array $group
     * @return string
     */
    private function sendTracking(array $group): string
    {
        $body = json_encode([
            'events' => $group['events'],
            'visitor_id' => $group['visitor_id'],
            'session_id' => $group['session_id'],
            'plugin_version' => 'server-' . Config::VERSION,
            'timestamp' => (int) round(microtime(true) * 1000),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === $body) {
            return HttpClient::DELIVERY_DELIVERED;
        }

        return $this->httpClient->deliver(
            $this->config->getAppOrigin() . '/api/webhooks/events',
            $body,
            $this->signer->buildHeaders($body, 'server-')
        );
    }

    /**
     * Deletes a handled row or schedules its retry, unless a hook rewrote it in the meantime.
     *
     * Only a refusal counts as an attempt: while the Fullmetrix API cannot be reached, the row just
     * waits a minute, so an outage never brings it closer to the purge.
     *
     * @param array $row
     * @param string $result
     * @return void
     */
    private function complete(array $row, string $result): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(WebhookQueue::TABLE);
            $where = [
                'queue_id = ?' => (int) $row['queue_id'],
                'payload = ?' => (string) $row['payload'],
            ];
            if (HttpClient::DELIVERY_DELIVERED === $result) {
                $connection->delete($table, $where);

                return;
            }
            if (HttpClient::DELIVERY_UNREACHABLE === $result) {
                $connection->update(
                    $table,
                    ['available_at' => gmdate('Y-m-d H:i:s', time() + self::UNREACHABLE_RETRY_SECONDS)],
                    $where
                );

                return;
            }
            $attempts = (int) $row['attempts'] + 1;
            $delay = min(self::MAX_RETRY_DELAY_SECONDS, 30 * (2 ** min(7, $attempts - 1)));
            $connection->update(
                $table,
                [
                    'attempts' => min(65535, $attempts),
                    'available_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                ],
                $where
            );
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
