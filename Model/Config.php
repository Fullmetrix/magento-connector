<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\FlagManager;

class Config
{
    public const VERSION = '1.0.0';

    public const FLAG_CONNECTION_CODE = 'fullmetrix_connection_code';
    public const FLAG_CONNECTION_SECRET = 'fullmetrix_connection_secret';
    public const FLAG_REGISTERED = 'fullmetrix_registered';
    public const FLAG_WEBHOOKS_ENABLED = 'fullmetrix_webhooks_enabled';
    public const FLAG_PLUGIN_CONFIG = 'fullmetrix_plugin_config';
    public const FLAG_PLUGIN_CONFIG_AT = 'fullmetrix_plugin_config_at';
    public const FLAG_API_BASE_OVERRIDE = 'fullmetrix_api_base';

    private const XML_PATH_API_BASE = 'fullmetrix/general/api_base';
    private const CONFIG_TTL_SECONDS = 1800;

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getApiBase(): string
    {
        $override = $this->flagManager->getFlagData(self::FLAG_API_BASE_OVERRIDE);
        if (\is_string($override) && '' !== trim($override)) {
            return rtrim(trim($override), '/');
        }
        $configured = (string) $this->scopeConfig->getValue(self::XML_PATH_API_BASE);

        return rtrim('' !== trim($configured) ? trim($configured) : 'https://fullmetrix.com/api/plugin', '/');
    }

    public function getAppOrigin(): string
    {
        $base = $this->getApiBase();
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

    public function getConnectionCode(): string
    {
        $value = $this->flagManager->getFlagData(self::FLAG_CONNECTION_CODE);

        return \is_string($value) ? $value : '';
    }

    public function getConnectionSecret(): string
    {
        $value = $this->flagManager->getFlagData(self::FLAG_CONNECTION_SECRET);

        return \is_string($value) ? $value : '';
    }

    public function isRegistered(): bool
    {
        return (bool) $this->flagManager->getFlagData(self::FLAG_REGISTERED)
            && '' !== $this->getConnectionCode()
            && '' !== $this->getConnectionSecret();
    }

    public function areWebhooksEnabled(): bool
    {
        $value = $this->flagManager->getFlagData(self::FLAG_WEBHOOKS_ENABLED);

        return null === $value ? true : (bool) $value;
    }

    public function isActive(): bool
    {
        return $this->isRegistered() && $this->areWebhooksEnabled();
    }

    public function saveConnection(string $code, string $secret): void
    {
        $this->flagManager->saveFlag(self::FLAG_CONNECTION_CODE, $code);
        $this->flagManager->saveFlag(self::FLAG_CONNECTION_SECRET, $secret);
        $this->flagManager->saveFlag(self::FLAG_REGISTERED, true);
        $this->flagManager->saveFlag(self::FLAG_WEBHOOKS_ENABLED, true);
        $this->flagManager->deleteFlag(self::FLAG_PLUGIN_CONFIG);
        $this->flagManager->deleteFlag(self::FLAG_PLUGIN_CONFIG_AT);
    }

    public function clearConnection(): void
    {
        $this->flagManager->deleteFlag(self::FLAG_CONNECTION_CODE);
        $this->flagManager->deleteFlag(self::FLAG_CONNECTION_SECRET);
        $this->flagManager->deleteFlag(self::FLAG_REGISTERED);
        $this->flagManager->deleteFlag(self::FLAG_PLUGIN_CONFIG);
        $this->flagManager->deleteFlag(self::FLAG_PLUGIN_CONFIG_AT);
    }

    public function getCachedPluginConfig(): ?array
    {
        $storedAt = (int) $this->flagManager->getFlagData(self::FLAG_PLUGIN_CONFIG_AT);
        if (0 === $storedAt || (time() - $storedAt) > self::CONFIG_TTL_SECONDS) {
            return null;
        }
        $data = $this->flagManager->getFlagData(self::FLAG_PLUGIN_CONFIG);

        return \is_array($data) ? $data : null;
    }

    public function savePluginConfig(array $config): void
    {
        $this->flagManager->saveFlag(self::FLAG_PLUGIN_CONFIG, $config);
        $this->flagManager->saveFlag(self::FLAG_PLUGIN_CONFIG_AT, time());
    }

    public function setApiBaseOverride(?string $apiBase): void
    {
        if (null === $apiBase || '' === trim($apiBase)) {
            $this->flagManager->deleteFlag(self::FLAG_API_BASE_OVERRIDE);

            return;
        }
        $this->flagManager->saveFlag(self::FLAG_API_BASE_OVERRIDE, rtrim(trim($apiBase), '/'));
    }
}
