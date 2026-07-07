<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class TrackingQueue
{
    private array $events = [];
    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly Config $config,
        private readonly HmacSigner $signer,
        private readonly HttpClient $httpClient,
        private readonly CookieReader $cookieReader,
    ) {
    }

    public function enqueue(string $eventType, array $properties = [], ?array $contact = null, ?string $pageUrl = null): void
    {
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
