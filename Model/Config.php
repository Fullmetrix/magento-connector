<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\FlagManager;

class Config
{
    public const VERSION = '1.5.1';

    public const FLAG_CONNECTION_CODE = 'fullmetrix_connection_code';
    public const FLAG_CONNECTION_SECRET = 'fullmetrix_connection_secret';
    public const FLAG_REGISTERED = 'fullmetrix_registered';
    public const FLAG_WEBHOOKS_ENABLED = 'fullmetrix_webhooks_enabled';
    public const FLAG_PLUGIN_CONFIG = 'fullmetrix_plugin_config';
    public const FLAG_PLUGIN_CONFIG_AT = 'fullmetrix_plugin_config_at';
    public const FLAG_PLUGIN_CONFIG_FAILED_AT = 'fullmetrix_plugin_config_failed_at';
    public const FLAG_API_BASE_OVERRIDE = 'fullmetrix_api_base';
    public const FLAG_STORE_ID = 'fullmetrix_store_id';
    public const FLAG_INSTALLATION_ID = 'fullmetrix_installation_id';
    public const FLAG_REFRESH_ALL_PRODUCTS = 'fullmetrix_refresh_all_products';
    public const FLAG_LAST_SYNC = 'fullmetrix_last_sync';
    public const FLAG_SYNC_IN_PROGRESS = 'fullmetrix_sync_in_progress';
    public const FLAG_QUEUE_PURGED_AT = 'fullmetrix_queue_purged_at';

    private const XML_PATH_API_BASE = 'fullmetrix/general/api_base';
    private const CONFIG_TTL_SECONDS = 1800;
    private const CONFIG_FAILURE_TTL_SECONDS = 300;
    private const SYNC_STALE_AFTER_SECONDS = 600;
    private const FLAG_CACHE_TTL_SECONDS = 60;

    /**
     * @var array
     */
    private array $flags = [];

    /**
     * @var array
     */
    private array $flagsReadAt = [];

    /**
     * @param FlagManager $flagManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Returns the Fullmetrix plugin API base URL.
     *
     * @return string
     */
    public function getApiBase(): string
    {
        $override = $this->readFlag(self::FLAG_API_BASE_OVERRIDE);
        if (\is_string($override) && '' !== trim($override)) {
            return rtrim(trim($override), '/');
        }
        $configured = (string) $this->scopeConfig->getValue(self::XML_PATH_API_BASE);

        return rtrim('' !== trim($configured) ? trim($configured) : 'https://fullmetrix.com/api/plugin', '/');
    }

    /**
     * Returns the origin of the Fullmetrix application.
     *
     * @return string
     */
    public function getAppOrigin(): string
    {
        $base = $this->getApiBase();
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parts = parse_url($base);
        if (false === $parts || empty($parts['host'])) {
            return 'https://fullmetrix.com';
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    /**
     * Returns the connection code entered by the merchant.
     *
     * @return string
     */
    public function getConnectionCode(): string
    {
        $value = $this->readFlag(self::FLAG_CONNECTION_CODE);

        return \is_string($value) ? $value : '';
    }

    /**
     * Returns the shared secret used to sign requests.
     *
     * @return string
     */
    public function getConnectionSecret(): string
    {
        $value = $this->readFlag(self::FLAG_CONNECTION_SECRET);

        return \is_string($value) ? $value : '';
    }

    /**
     * Tells whether the store is paired with a Fullmetrix account.
     *
     * @return bool
     */
    public function isRegistered(): bool
    {
        return (bool) $this->readFlag(self::FLAG_REGISTERED)
            && '' !== $this->getConnectionCode()
            && '' !== $this->getConnectionSecret();
    }

    /**
     * Tells whether webhooks may be sent.
     *
     * @return bool
     */
    public function areWebhooksEnabled(): bool
    {
        $value = $this->readFlag(self::FLAG_WEBHOOKS_ENABLED);

        return null === $value ? true : (bool) $value;
    }

    /**
     * Tells whether the connector is registered and allowed to send data.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->isRegistered() && $this->areWebhooksEnabled();
    }

    /**
     * Stores the credentials returned by Fullmetrix after pairing.
     *
     * @param string $code
     * @param string $secret
     * @param int $storeId
     * @return void
     */
    public function saveConnection(string $code, string $secret, int $storeId): void
    {
        $this->writeFlag(self::FLAG_CONNECTION_CODE, $code);
        $this->writeFlag(self::FLAG_CONNECTION_SECRET, $secret);
        $this->writeFlag(self::FLAG_REGISTERED, true);
        $this->writeFlag(self::FLAG_WEBHOOKS_ENABLED, true);
        $this->writeFlag(self::FLAG_STORE_ID, $storeId);
        $this->removeFlag(self::FLAG_PLUGIN_CONFIG);
        $this->removeFlag(self::FLAG_PLUGIN_CONFIG_AT);
    }

    /**
     * Returns a stable identifier for a store view of this installation.
     *
     * @param int $storeId
     * @return string
     */
    public function getStoreCanonicalId(int $storeId): string
    {
        $installationId = $this->readFlag(self::FLAG_INSTALLATION_ID);
        if (!\is_string($installationId) || '' === $installationId) {
            $installationId = bin2hex(random_bytes(16));
            $this->writeFlag(self::FLAG_INSTALLATION_ID, $installationId);
        }

        return hash('sha256', $installationId . ':' . $storeId);
    }

    /**
     * Erases every trace of the current connection.
     *
     * @return void
     */
    public function clearConnection(): void
    {
        $this->removeFlag(self::FLAG_CONNECTION_CODE);
        $this->removeFlag(self::FLAG_CONNECTION_SECRET);
        $this->removeFlag(self::FLAG_REGISTERED);
        $this->removeFlag(self::FLAG_STORE_ID);
        $this->removeFlag(self::FLAG_PLUGIN_CONFIG);
        $this->removeFlag(self::FLAG_PLUGIN_CONFIG_AT);
        $this->removeFlag(self::FLAG_PLUGIN_CONFIG_FAILED_AT);
        $this->removeFlag(self::FLAG_REFRESH_ALL_PRODUCTS);
        $this->removeFlag(self::FLAG_LAST_SYNC);
        $this->removeFlag(self::FLAG_SYNC_IN_PROGRESS);
    }

    /**
     * Records that an export has started.
     *
     * @param string $syncType
     * @return void
     */
    public function markSyncStarted(string $syncType): void
    {
        $this->writeFlag(self::FLAG_SYNC_IN_PROGRESS, [
            'started_at' => time(),
            'type' => $syncType,
        ]);
    }

    /**
     * Records the row counts of a finished export.
     *
     * @param array $counts
     * @return void
     */
    public function markSyncCompleted(array $counts): void
    {
        $previous = $this->readFlag(self::FLAG_LAST_SYNC);
        $entities = \is_array($previous) && \is_array($previous['entities'] ?? null)
            ? $previous['entities']
            : [];

        foreach ($counts as $entity => $count) {
            $entities[$entity] = (int) $count;
        }

        $this->writeFlag(self::FLAG_LAST_SYNC, [
            'completed_at' => time(),
            'entities' => $entities,
        ]);
        $this->removeFlag(self::FLAG_SYNC_IN_PROGRESS);
    }

    /**
     * Tells whether an export is running, clearing the flag once it goes stale.
     *
     * @return bool
     */
    public function isSyncInProgress(): bool
    {
        $value = $this->readFlag(self::FLAG_SYNC_IN_PROGRESS);
        if (!\is_array($value)) {
            return false;
        }

        $startedAt = (int) ($value['started_at'] ?? 0);
        if ($startedAt <= 0 || (time() - $startedAt) > self::SYNC_STALE_AFTER_SECONDS) {
            $this->removeFlag(self::FLAG_SYNC_IN_PROGRESS);

            return false;
        }

        return true;
    }

    /**
     * Returns the row count exported per entity during the last sync.
     *
     * @return array
     */
    public function getLastSyncEntities(): array
    {
        $value = $this->readFlag(self::FLAG_LAST_SYNC);
        if (!\is_array($value) || !\is_array($value['entities'] ?? null)) {
            return [];
        }

        $entities = [];
        foreach ($value['entities'] as $entity => $count) {
            $entities[(string) $entity] = (int) $count;
        }

        return $entities;
    }

    /**
     * Tells whether at least one export has completed.
     *
     * @return bool
     */
    public function hasCompletedSync(): bool
    {
        $value = $this->readFlag(self::FLAG_LAST_SYNC);

        return \is_array($value) && (int) ($value['completed_at'] ?? 0) > 0;
    }

    /**
     * Returns the plugin configuration pulled from Fullmetrix, if still fresh.
     *
     * @return ?array
     */
    public function getCachedPluginConfig(): ?array
    {
        $storedAt = (int) $this->readFlag(self::FLAG_PLUGIN_CONFIG_AT);
        if (0 === $storedAt || (time() - $storedAt) > self::CONFIG_TTL_SECONDS) {
            return null;
        }
        $data = $this->readFlag(self::FLAG_PLUGIN_CONFIG);

        return \is_array($data) ? $data : null;
    }

    /**
     * Returns the last plugin configuration pulled, however old it is.
     *
     * @return ?array
     */
    public function getStalePluginConfig(): ?array
    {
        $data = $this->readFlag(self::FLAG_PLUGIN_CONFIG);

        return \is_array($data) ? $data : null;
    }

    /**
     * Tells whether a failed configuration fetch should not be retried yet.
     *
     * @return bool
     */
    public function isPluginConfigFetchOnCooldown(): bool
    {
        $failedAt = (int) $this->readFlag(self::FLAG_PLUGIN_CONFIG_FAILED_AT);

        return $failedAt > 0 && (time() - $failedAt) < self::CONFIG_FAILURE_TTL_SECONDS;
    }

    /**
     * Stores the plugin configuration pulled from Fullmetrix.
     *
     * @param array $config
     * @return void
     */
    public function savePluginConfig(array $config): void
    {
        $this->writeFlag(self::FLAG_PLUGIN_CONFIG, $config);
        $this->writeFlag(self::FLAG_PLUGIN_CONFIG_AT, time());
        $this->removeFlag(self::FLAG_PLUGIN_CONFIG_FAILED_AT);
    }

    /**
     * Records that pulling the plugin configuration failed.
     *
     * @return void
     */
    public function markPluginConfigFetchFailed(): void
    {
        $this->writeFlag(self::FLAG_PLUGIN_CONFIG_FAILED_AT, time());
    }

    /**
     * Overrides the API base URL, or clears the override.
     *
     * @param string|null $apiBase
     * @return void
     */
    public function setApiBaseOverride(?string $apiBase): void
    {
        if (null === $apiBase || '' === trim($apiBase)) {
            $this->removeFlag(self::FLAG_API_BASE_OVERRIDE);

            return;
        }
        $this->writeFlag(self::FLAG_API_BASE_OVERRIDE, rtrim(trim($apiBase), '/'));
    }

    /**
     * Asks the next export to resend every product.
     *
     * @return void
     */
    public function markAllProductsForRefresh(): void
    {
        if ($this->isRegistered()) {
            $this->writeFlag(self::FLAG_REFRESH_ALL_PRODUCTS, true);
        }
    }

    /**
     * Tells whether every product must be resent.
     *
     * @return bool
     */
    public function shouldRefreshAllProducts(): bool
    {
        return (bool) $this->readFlag(self::FLAG_REFRESH_ALL_PRODUCTS);
    }

    /**
     * Clears the request to resend every product.
     *
     * @return void
     */
    public function clearAllProductsRefresh(): void
    {
        $this->removeFlag(self::FLAG_REFRESH_ALL_PRODUCTS);
    }

    /**
     * Returns when the webhook queue was last purged, 0 when never.
     *
     * @return int
     */
    public function getQueuePurgedAt(): int
    {
        return (int) $this->readFlag(self::FLAG_QUEUE_PURGED_AT);
    }

    /**
     * Records that the webhook queue has just been purged.
     *
     * @return void
     */
    public function markQueuePurged(): void
    {
        $this->writeFlag(self::FLAG_QUEUE_PURGED_AT, time());
    }

    /**
     * Returns the store view chosen at pairing, 0 when none.
     *
     * @return int
     */
    public function getConfiguredStoreId(): int
    {
        return (int) $this->readFlag(self::FLAG_STORE_ID);
    }

    /**
     * Reads a flag at most once a minute, the flag table has no cache of its own.
     *
     * Long lived processes (queue consumers, application server) see a pairing or a disconnection
     * within a minute.
     *
     * @param string $code
     * @return mixed
     */
    private function readFlag(string $code): mixed
    {
        $now = time();
        if (!\array_key_exists($code, $this->flags)
            || $now - ($this->flagsReadAt[$code] ?? 0) >= self::FLAG_CACHE_TTL_SECONDS
        ) {
            $this->flags[$code] = $this->flagManager->getFlagData($code);
            $this->flagsReadAt[$code] = $now;
        }

        return $this->flags[$code];
    }

    /**
     * Writes a flag and keeps the request cache in step.
     *
     * @param string $code
     * @param mixed $value
     * @return void
     */
    private function writeFlag(string $code, mixed $value): void
    {
        unset($this->flags[$code]);
        $this->flagManager->saveFlag($code, $value);
        $this->flags[$code] = $value;
        $this->flagsReadAt[$code] = time();
    }

    /**
     * Deletes a flag and keeps the request cache in step.
     *
     * @param string $code
     * @return void
     */
    private function removeFlag(string $code): void
    {
        unset($this->flags[$code]);
        $this->flagManager->deleteFlag($code);
        $this->flags[$code] = null;
        $this->flagsReadAt[$code] = time();
    }
}
