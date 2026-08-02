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
        private readonly WebhookQueue $webhookQueue,
    ) {
    }

    public function connect(string $connectionCode, ?int $storeId = null): array
    {
        $connectionCode = strtoupper(trim($connectionCode));
        if (1 !== preg_match(self::CODE_PATTERN, $connectionCode)) {
            return ['success' => false, 'error' => 'invalid_code_format'];
        }

        try {
            $store = null !== $storeId
                ? $this->storeSettings->getStoreById($storeId)
                : $this->storeSettings->getStore();
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'invalid_store'];
        }

        $selectedStoreId = (int) $store->getId();
        if ($selectedStoreId <= 0 || !(bool) $store->isActive()) {
            return ['success' => false, 'error' => 'invalid_store'];
        }
        $result = $this->apiClient->register(
            $connectionCode,
            $this->storeSettings->getSiteUrl($selectedStoreId),
            $selectedStoreId
        );
        if (!$result['success']) {
            return $result;
        }

        $this->webhookQueue->clear();
        $this->config->saveConnection($connectionCode, $result['connectionSecret'], $selectedStoreId);

        return ['success' => true];
    }

    public function disconnect(): void
    {
        $this->webhookQueue->clear();
        $this->config->clearConnection();
    }
}
