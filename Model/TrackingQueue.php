<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class TrackingQueue
{
    /**
     * @param WebhookQueue $webhookQueue
     * @param CookieReader $cookieReader
     */
    public function __construct(
        private readonly WebhookQueue $webhookQueue,
        private readonly CookieReader $cookieReader,
    ) {
    }

    /**
     * Records a server side tracking event, the cron adds the cart and sends it.
     *
     * @param string $eventType
     * @param array $properties
     * @param array|null $contact
     * @param int $quoteId
     * @param bool $requireQuote
     * @return void
     */
    public function enqueue(
        string $eventType,
        array $properties = [],
        ?array $contact = null,
        int $quoteId = 0,
        bool $requireQuote = false
    ): void {
        $visitorId = $this->cookieReader->getVisitorId();
        $sessionId = $this->cookieReader->getSessionId();
        if (null === $visitorId || null === $sessionId) {
            return;
        }
        $eventId = 'srv_' . bin2hex(random_bytes(12));
        $event = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'properties' => $properties,
            'occurred_at' => (int) round(microtime(true) * 1000),
        ];
        if (null !== $contact && \count($contact) > 0) {
            $event['contact'] = $contact;
        }
        $data = [
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'event' => $event,
        ];
        if ($quoteId > 0) {
            $data['quote_id'] = $quoteId;
            $data['require_quote'] = $requireQuote;
        }
        $rowId = 'cart_updated' === $eventType && $quoteId > 0 ? 'cart_updated:' . $quoteId : $eventId;
        $this->webhookQueue->enqueueData(WebhookQueue::TYPE_TRACKING, $rowId, $eventType, $data);
    }
}
