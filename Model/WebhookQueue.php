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
    ) {
    }

    public function enqueue(string $entityType, int|string $entityId, array $data, string $event): void
    {
        if (!$this->config->isActive()) {
            return;
        }
        $this->queue[$entityType . ':' . $entityId] = [
            'event' => $event,
            'entity_type' => $entityType,
            'data' => $data,
        ];
        $this->registerShutdown();
    }

    public function flush(): void
    {
        if (0 === \count($this->queue)) {
            return;
        }
        $pending = $this->queue;
        $this->queue = [];

        $endpoint = $this->config->getAppOrigin() . '/api/webhooks/ecommerce';
        $timestampMs = (int) round(microtime(true) * 1000);

        foreach ($pending as $item) {
            $body = json_encode([
                'event' => $item['event'],
                'entity_type' => $item['entity_type'],
                'data' => $item['data'],
                'plugin_version' => Config::VERSION,
                'timestamp' => $timestampMs,
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            if (false === $body) {
                continue;
            }
            $this->httpClient->postFireAndForget(
                $endpoint,
                $body,
                $this->signer->buildHeaders($body)
            );
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
                if (\function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $this->flush();
            } catch (\Throwable) {
            }
        });
    }
}
