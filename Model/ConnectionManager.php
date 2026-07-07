<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class ConnectionManager
{
    private const CODE_PATTERN = '/^FMTX-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/';

    public function __construct(
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly StoreSettingsProvider $storeSettings,
    ) {
    }

    public function connect(string $connectionCode): array
    {
        $connectionCode = strtoupper(trim($connectionCode));
        if (1 !== preg_match(self::CODE_PATTERN, $connectionCode)) {
            return ['success' => false, 'error' => 'invalid_code_format'];
        }

        $result = $this->apiClient->register($connectionCode, $this->storeSettings->getSiteUrl());
        if (!$result['success']) {
            return $result;
        }

        $this->config->saveConnection($connectionCode, $result['connectionSecret']);

        return ['success' => true];
    }

    public function disconnect(): void
    {
        $this->config->clearConnection();
    }
}
