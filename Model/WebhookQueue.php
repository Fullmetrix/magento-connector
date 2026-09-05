<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class WebhookQueue
{
    /**
     * @var array
     */
    private array $queue = [];
    /**
     * @var bool
     */
    private bool $shutdownRegistered = false;

    /**
     * @param Config $config
     * @param HmacSigner $signer
     * @param HttpClient $httpClient
     * @param Magento\Framework\App\ResourceConnection $resourceConnection
     * @param Magento\Framework\Lock\LockManagerInterface $lockManager
     */
    public function __construct(
        private readonly Config $config,
        private readonly HmacSigner $signer,
        private readonly HttpClient $httpClient,
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection,
        private readonly \Magento\Framework\Lock\LockManagerInterface $lockManager,
    ) {
    }

    /**
     * Enqueue.
     *
     * @param string $entityType
     * @param int|string $entityId
     * @param array $data
     * @param string $event
     * @return void
     */
    public function enqueue(string $entityType, int|string $entityId, array $data, string $event): void
    {
        if (!$this->config->isActive()) {
            return;
        }
        $item = [
            'event' => $event,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'data' => $data,
        ];
        if (!$this->persist($item)) {
            $this->queue[$entityType . ':' . $entityId] = $item;
        }
        $this->registerShutdown();
    }

    /**
     * Enqueue deleted.
     *
     * @param string $entityType
     * @param int|string $entityId
     * @return void
     */
    public function enqueueDeleted(string $entityType, int|string $entityId): void
    {
        $this->enqueue($entityType, $entityId, ['id' => (string) $entityId], 'deleted');
    }

    /**
     * Enqueue consent.
     *
     * @param string $email
     * @param bool $consent
     * @param string $phone
     * @param string $country
     * @return void
     */
    public function enqueueConsent(string $email, bool $consent, string $phone = '', string $country = ''): void
    {
        $email = strtolower(trim($email));
        if (!$this->config->isActive() || '' === $email) {
            return;
        }
        $item = [
            'event' => 'consent',
            'entity_type' => 'consent',
            'entity_id' => hash('sha256', $email),
            'data' => [
                'email' => $email,
                'phone' => $phone,
                'country' => strtoupper(trim($country)),
                'consent' => $consent,
            ],
        ];
        if (!$this->persist($item)) {
            $this->queue['consent:' . $item['entity_id']] = $item;
        }
        $this->registerShutdown();
    }

    /**
     * Flush.
     *
     * @return void
     */
    public function flush(): void
    {
        if (!$this->config->isActive()) {
            return;
        }
        if (!$this->lockManager->lock('fullmetrix_webhook_queue_flush', 0)) {
            return;
        }
        try {
            $this->flushLocked();
        } finally {
            $this->lockManager->unlock('fullmetrix_webhook_queue_flush');
        }
    }

    /**
     * Flush locked.
     *
     * @return void
     */
    private function flushLocked(): void
    {
        HttpClient::finishResponse();
        $pending = $this->queue;
        $this->queue = [];
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('fullmetrix_webhook_queue');
            $select = $connection->select()->from($table)
                ->where('available_at <= UTC_TIMESTAMP()')
                ->order('queue_id ASC')
                ->limit(50);
            foreach ($connection->fetchAll($select) as $row) {
                $data = json_decode((string) $row['payload'], true);
                if (!\is_array($data)) {
                    $connection->delete($table, ['queue_id = ?' => (int) $row['queue_id']]);
                    continue;
                }
                $pending['persisted:' . $row['queue_id']] = [
                    'queue_id' => (int) $row['queue_id'],
                    'attempts' => (int) $row['attempts'],
                    'event' => (string) $row['event'],
                    'entity_type' => (string) $row['entity_type'],
                    'entity_id' => (string) $row['entity_id'],
                    'data' => $data,
                ];
            }
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
        if (0 === \count($pending)) {
            return;
        }

        $timestampMs = (int) round(microtime(true) * 1000);
        $deliveryAvailable = true;

        foreach ($pending as $item) {
            $isConsent = 'consent' === $item['event'];
            $path = $isConsent ? '/api/checkout-consent' : '/api/webhooks/ecommerce';
            $endpoint = $this->config->getAppOrigin() . $path;
            $body = json_encode($isConsent ? [
                'key' => $this->config->getConnectionCode(),
                'email' => $item['data']['email'],
                'phone' => $item['data']['phone'],
                'country' => $item['data']['country'] ?? '',
                'consent' => $item['data']['consent'],
                'channels' => ['email'],
                'pageUrl' => '',
            ] : [
                'event' => $item['event'],
                'entity_type' => $item['entity_type'],
                'data' => $item['data'],
                'plugin_version' => Config::VERSION,
                'timestamp' => $timestampMs,
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            if (false === $body) {
                continue;
            }
            $sent = $deliveryAvailable && $this->httpClient->postFireAndForget(
                $endpoint,
                $body,
                $this->signer->buildHeaders($body)
            );
            if (!$sent) {
                $deliveryAvailable = false;
            }
            if (isset($item['queue_id'])) {
                $this->completePersisted($item, $sent);
            }
        }
    }

    /**
     * Clears the.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->queue = [];
        try {
            $this->resourceConnection->getConnection()->delete(
                $this->resourceConnection->getTableName('fullmetrix_webhook_queue')
            );
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }

    /**
     * Persist.
     *
     * @param array $item
     * @return bool
     */
    private function persist(array $item): bool
    {
        try {
            $payload = json_encode($item['data'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            if (false === $payload) {
                return false;
            }
            $connection = $this->resourceConnection->getConnection();
            $connection->insertOnDuplicate(
                $this->resourceConnection->getTableName('fullmetrix_webhook_queue'),
                [
                    'entity_type' => $item['entity_type'],
                    'entity_id' => $item['entity_id'],
                    'event' => $item['event'],
                    'payload' => $payload,
                    'attempts' => 0,
                    'available_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['event', 'payload', 'attempts', 'available_at']
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Complete persisted.
     *
     * @param array $item
     * @param bool $sent
     * @return void
     */
    private function completePersisted(array $item, bool $sent): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('fullmetrix_webhook_queue');
            if ($sent) {
                $connection->delete($table, ['queue_id = ?' => (int) $item['queue_id']]);
                return;
            }
            $attempts = (int) $item['attempts'] + 1;
            $delay = min(3600, 30 * (2 ** min(7, $attempts - 1)));
            $connection->update(
                $table,
                [
                    'attempts' => $attempts,
                    'available_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                ],
                ['queue_id = ?' => (int) $item['queue_id']]
            );
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }

    /**
     * Register shutdown.
     *
     * @return void
     */
    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        register_shutdown_function(function (): void {
            try {
                $this->flush();
            // The failure is optional data, the caller keeps going.
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            } catch (\Throwable) {
            }
        });
    }
}
