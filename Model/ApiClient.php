<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class ApiClient
{
    public function __construct(
        private readonly Config $config,
        private readonly HmacSigner $signer,
        private readonly HttpClient $httpClient,
        private readonly StoreSettingsProvider $storeSettings,
    ) {
    }

    public function register(string $connectionCode, string $siteUrl): array
    {
        $payload = json_encode([
            'connectionCode' => $connectionCode,
            'siteUrl' => $siteUrl,
            'pluginVersion' => Config::VERSION,
            'platform' => 'magento',
            'storeSettings' => $this->storeSettings->getSettings(),
        ], \JSON_UNESCAPED_SLASHES) ?: '{}';

        $response = $this->httpClient->postJson(
            $this->config->getApiBase() . '/register',
            $payload,
            [
                'Content-Type: application/json',
                'X-Fullmetrix-Plugin-Version: ' . Config::VERSION,
            ],
            20
        );

        if (200 !== $response['status']) {
            $decoded = json_decode($response['body'], true);
            $message = \is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : ('' !== $response['error'] ? $response['error'] : 'HTTP ' . $response['status']);

            return ['success' => false, 'error' => $message];
        }

        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded) || empty($decoded['connectionSecret'])) {
            return ['success' => false, 'error' => 'invalid_response'];
        }

        return ['success' => true, 'connectionSecret' => (string) $decoded['connectionSecret']];
    }

    public function fetchPluginConfig(): ?array
    {
        $cached = $this->config->getCachedPluginConfig();
        if (null !== $cached) {
            return $cached;
        }
        if (!$this->config->isRegistered()) {
            return null;
        }

        $response = $this->httpClient->getJson(
            $this->config->getApiBase() . '/config',
            $this->signer->buildHeaders(''),
            10
        );

        if (200 !== $response['status']) {
            return null;
        }
        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded)) {
            return null;
        }
        $this->config->savePluginConfig($decoded);

        return $decoded;
    }

    public function isTrackerEnabled(): bool
    {
        $pluginConfig = $this->fetchPluginConfig();
        if (null === $pluginConfig) {
            return true;
        }

        return !\array_key_exists('trackerEnabled', $pluginConfig) || false !== $pluginConfig['trackerEnabled'];
    }
}
