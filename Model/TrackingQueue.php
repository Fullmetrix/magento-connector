<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class TrackingQueue
{
    /**
     * @var array
     */
    private array $events = [];
    /**
     * @var bool
     */
    private bool $shutdownRegistered = false;

    /**
     * @param Config $config
     * @param HmacSigner $signer
     * @param HttpClient $httpClient
     * @param CookieReader $cookieReader
     */
    public function __construct(
        private readonly Config $config,
        private readonly HmacSigner $signer,
        private readonly HttpClient $httpClient,
        private readonly CookieReader $cookieReader,
    ) {
    }

    /**
     * Enqueue.
     *
     * @param string $eventType
     * @param array $properties
     * @param array|null $contact
     * @param string|null $pageUrl
     * @return void
     */
    public function enqueue(
        string $eventType,
        array $properties = [],
        ?array $contact = null,
        ?string $pageUrl = null
    ): void {
        if (!$this->config->isActive()) {
            return;
        }
        $event = [
            'event_id' => 'srv_' . bin2hex(random_bytes(12)),
            'event_type' => $eventType,
            'properties' => (object) $properties,
            'occurred_at' => (int) round(microtime(true) * 1000),
        ];
        if (null !== $contact && \count($contact) > 0) {
            $event['contact'] = $contact;
        }
        if (null !== $pageUrl && '' !== $pageUrl) {
            $event['page'] = ['url' => $pageUrl];
        }
        $this->events[] = $event;
        $this->registerShutdown();
    }

    /**
     * Flush.
     *
     * @return void
     */
    public function flush(): void
    {
        if (0 === \count($this->events)) {
            return;
        }
        $visitorId = $this->cookieReader->getVisitorId();
        $sessionId = $this->cookieReader->getSessionId();
        if (null === $visitorId || null === $sessionId) {
            $this->events = [];

            return;
        }

        HttpClient::finishResponse();
        $pending = $this->events;
        $this->events = [];

        $body = json_encode([
            'events' => $pending,
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'plugin_version' => 'server-' . Config::VERSION,
            'timestamp' => (int) round(microtime(true) * 1000),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === $body) {
            return;
        }

        $this->httpClient->postFireAndForget(
            $this->config->getAppOrigin() . '/api/webhooks/events',
            $body,
            $this->signer->buildHeaders($body, 'server-')
        );
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
