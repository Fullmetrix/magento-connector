<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\CacheInterface;

class CartLinkResolver
{
    private const DOWN_KEY = 'fullmetrix_cart_resolve_down';
    private const FAILURE_KEY = 'fullmetrix_cart_resolve_failures';
    private const DOWN_TTL = 60;
    private const FAILURES_BEFORE_DOWN = 3;
    private const CONNECT_TIMEOUT_MS = 300;
    private const TOTAL_TIMEOUT_MS = 1500;

    /**
     * @param Config $config
     * @param HmacSigner $signer
     * @param HttpClient $http
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly Config $config,
        private readonly HmacSigner $signer,
        private readonly HttpClient $http,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Opens the breaker only after repeated failures, never on a single latency spike.
     *
     * @return void
     */
    private function recordFailure(): void
    {
        $failures = ((int) $this->cache->load(self::FAILURE_KEY)) + 1;

        if ($failures >= self::FAILURES_BEFORE_DOWN) {
            $this->cache->save('1', self::DOWN_KEY, [], self::DOWN_TTL);
            $this->cache->remove(self::FAILURE_KEY);

            return;
        }

        $this->cache->save((string) $failures, self::FAILURE_KEY, [], self::DOWN_TTL);
    }

    /**
     * Resolves a recovery link into cart contents.
     *
     * @param string $linkId
     * @return array|null
     */
    public function resolve(string $linkId): ?array
    {
        if ('' === $linkId || \strlen($linkId) > 64) {
            return null;
        }
        if (!$this->config->isRegistered()) {
            return null;
        }
        if ($this->cache->load(self::DOWN_KEY)) {
            return null;
        }

        $body = json_encode(['id' => $linkId]);
        if (!\is_string($body)) {
            return null;
        }

        $headers = $this->signer->buildHeaders($body);
        $url = rtrim($this->config->getApiBase(), '/') . '/cart/resolve';

        $response = $this->http->postJson(
            $url,
            $body,
            $headers,
            10,
            self::CONNECT_TIMEOUT_MS,
            self::TOTAL_TIMEOUT_MS
        );
        $status = (int) ($response['status'] ?? 0);

        if (0 === $status || $status >= 500) {
            $this->recordFailure();

            return null;
        }

        $this->cache->remove(self::FAILURE_KEY);

        if (200 !== $status) {
            return null;
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (!\is_array($decoded) || !\is_array($decoded['items'] ?? null)) {
            return null;
        }

        $items = [];
        foreach ($decoded['items'] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $items[] = [
                'id' => $item['id'] ?? 0,
                'v' => $item['variation'] ?? 0,
                'q' => $item['quantity'] ?? 1,
            ];
        }

        return [
            'items' => $items,
            'c' => \is_array($decoded['coupons'] ?? null) ? $decoded['coupons'] : [],
            'target' => \is_string($decoded['target'] ?? null) ? $decoded['target'] : 'cart',
        ];
    }
}
