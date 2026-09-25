<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\Model\CallbackPool;

class WebhookQueue
{
    public const TABLE = 'fullmetrix_webhook_queue';
    public const FORMAT = 2;
    public const TYPE_TRACKING = 'tracking';
    public const TYPE_CONSENT = 'consent';

    private const ROWS_PER_STATEMENT = 1000;

    /**
     * @param Config $config
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Records that an entity changed, the cron serializes and sends it.
     *
     * @param string $entityType
     * @param int|string $entityId
     * @param string $event
     * @return void
     */
    public function enqueue(string $entityType, int|string $entityId, string $event): void
    {
        $this->enqueueMany($entityType, [$entityId], $event);
    }

    /**
     * Records that several entities of one type changed, in a single statement.
     *
     * @param string $entityType
     * @param array $entityIds
     * @param string $event
     * @return void
     */
    public function enqueueMany(string $entityType, array $entityIds, string $event): void
    {
        $rows = [];
        foreach ($entityIds as $entityId) {
            $rows[] = $this->row($entityType, (string) $entityId, $event, null);
        }
        $this->write($rows);
    }

    /**
     * Records a deletion, the identifier is all the payload there is.
     *
     * @param string $entityType
     * @param int|string $entityId
     * @return void
     */
    public function enqueueDeleted(string $entityType, int|string $entityId): void
    {
        $this->enqueueDeletedMany($entityType, [$entityId]);
    }

    /**
     * Records several deletions of one type, in a single statement.
     *
     * @param string $entityType
     * @param array $entityIds
     * @return void
     */
    public function enqueueDeletedMany(string $entityType, array $entityIds): void
    {
        $rows = [];
        foreach (array_unique(array_map('strval', $entityIds)) as $entityId) {
            $rows[] = $this->row($entityType, $entityId, 'deleted', ['id' => $entityId]);
        }
        $this->write($rows);
    }

    /**
     * Records a newsletter consent change.
     *
     * @param string $email
     * @param bool $consent
     * @param string $phone
     * @param string $country
     * @param int $customerId
     * @param int|null $storeId
     * @return void
     */
    public function enqueueConsent(
        string $email,
        bool $consent,
        string $phone = '',
        string $country = '',
        int $customerId = 0,
        ?int $storeId = null
    ): void {
        $email = strtolower(trim($email));
        if ('' === $email) {
            return;
        }
        $data = [
            'email' => $email,
            'phone' => $phone,
            'country' => strtoupper(trim($country)),
            'consent' => $consent,
        ];
        if ($customerId > 0) {
            $data['customer_id'] = $customerId;
        }
        if (null !== $storeId) {
            $data['store_id'] = $storeId;
        }
        $this->enqueueData(self::TYPE_CONSENT, hash('sha256', $email), 'consent', $data);
    }

    /**
     * Records that an order was placed by someone whose subscription must be checked.
     *
     * @param string $email
     * @param int $websiteId
     * @param string $phone
     * @param string $country
     * @return void
     */
    public function enqueueOrderConsentCheck(string $email, int $websiteId, string $phone, string $country): void
    {
        $email = strtolower(trim($email));
        if ('' === $email) {
            return;
        }
        $this->enqueueData(self::TYPE_CONSENT, 'order:' . hash('sha256', $email), 'consent', [
            'email' => $email,
            'phone' => $phone,
            'country' => strtoupper(trim($country)),
            'consent' => true,
            'website_id' => $websiteId,
            'check_subscription' => true,
        ]);
    }

    /**
     * Records an event whose payload is already known.
     *
     * @param string $entityType
     * @param int|string $entityId
     * @param string $event
     * @param array $data
     * @return void
     */
    public function enqueueData(string $entityType, int|string $entityId, string $event, array $data): void
    {
        $this->write([$this->row($entityType, (string) $entityId, $event, $data)]);
    }

    /**
     * Empties the queue.
     *
     * @return void
     */
    public function clear(): void
    {
        try {
            $this->resourceConnection->getConnection()->delete(
                $this->resourceConnection->getTableName(self::TABLE)
            );
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }

    /**
     * Returns the number of pending rows and the age of the oldest one never attempted.
     *
     * @return array
     */
    public function stats(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()->from(
                $this->resourceConnection->getTableName(self::TABLE),
                [
                    'pending' => new \Zend_Db_Expr('COUNT(*)'),
                    'waiting_seconds' => new \Zend_Db_Expr(
                        'COALESCE(MAX(IF(attempts = 0, TIMESTAMPDIFF(SECOND, available_at, UTC_TIMESTAMP()), 0)), 0)'
                    ),
                ]
            );
            $row = $connection->fetchRow($select);

            return [
                'pending' => (int) ($row['pending'] ?? 0),
                'waiting_seconds' => max(0, (int) ($row['waiting_seconds'] ?? 0)),
            ];
        } catch (\Throwable) {
            return ['pending' => 0, 'waiting_seconds' => 0];
        }
    }

    /**
     * Builds one queue row.
     *
     * @param string $entityType
     * @param string $entityId
     * @param string $event
     * @param array|null $data
     * @return array
     */
    private function row(string $entityType, string $entityId, string $event, ?array $data): array
    {
        $payload = json_encode([
            '_fm' => self::FORMAT,
            'rev' => bin2hex(random_bytes(8)),
            'data' => $data,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event' => $event,
            'payload' => false === $payload ? '' : $payload,
            'attempts' => 0,
            'available_at' => gmdate('Y-m-d H:i:s'),
            'changed_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Inserts the rows, a pending row for the same entity is replaced by the newer one.
     *
     * Inside an open transaction the insert waits for the commit and is dropped on rollback:
     * a row lock on the queue must never outlive one statement, and a deadlock on the queue
     * must never be able to cut the merchant's transaction in half.
     *
     * @param array $rows
     * @return void
     * @throws DeadlockException When the insert broke a transaction that is still open.
     */
    private function write(array $rows): void
    {
        $connection = null;
        try {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => '' !== $row['payload'] && '' !== $row['entity_id']
            ));
            if ([] === $rows || !$this->config->isActive()) {
                return;
            }
            $connection = $this->resourceConnection->getConnection();
            if ($connection->getTransactionLevel() > 0) {
                CallbackPool::attach(spl_object_hash($connection), function () use ($rows): void {
                    $this->write($rows);
                });

                return;
            }
            usort(
                $rows,
                static fn (array $a, array $b): int
                    => [$a['entity_type'], $a['entity_id']] <=> [$b['entity_type'], $b['entity_id']]
            );
            $table = $this->resourceConnection->getTableName(self::TABLE);
            foreach (array_chunk($rows, self::ROWS_PER_STATEMENT) as $chunk) {
                $connection->insertOnDuplicate(
                    $table,
                    $chunk,
                    ['event', 'payload', 'attempts', 'available_at', 'changed_at']
                );
            }
        } catch (DeadlockException $e) {
            if (null !== $connection && $connection->getTransactionLevel() > 0) {
                throw $e;
            }
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
