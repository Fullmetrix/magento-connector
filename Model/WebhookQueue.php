<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class WebhookQueue
{
    private array $queue = [];
    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly Config $config,
        private readonly HmacSigner $signer,
        private readonly HttpClient $httpClient,
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection,
        private readonly \Magento\Framework\Lock\LockManagerInterface $lockManager,
    ) {
    }

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

    public function enqueueDeleted(string $entityType, int|string $entityId): void
    {
        $this->enqueue($entityType, $entityId, ['id' => (string) $entityId], 'deleted');
    }

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
        } catch (\Throwable) {
        }
        if (0 === \count($pending)) {
            return;
        }

        $timestampMs = (int) round(microtime(true) * 1000);
        $deliveryAvailable = true;

        foreach ($pending as $item) {
            $isConsent = 'consent' === $item['event'];
            $endpoint = $this->config->getAppOrigin() . ($isConsent ? '/api/checkout-consent' : '/api/webhooks/ecommerce');
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

    public function clear(): void
    {
        $this->queue = [];
        try {
            $this->resourceConnection->getConnection()->delete(
                $this->resourceConnection->getTableName('fullmetrix_webhook_queue')
            );
        } catch (\Throwable) {
        }
    }

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
        } catch (\Throwable) {
        }
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            try {
                $this->flush();
            } catch (\Throwable) {
            }
        });
    }
}
