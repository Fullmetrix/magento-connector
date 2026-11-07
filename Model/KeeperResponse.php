<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\ResponseInterface;

class KeeperResponse implements ResponseInterface
{
    /**
     * @param int $status
     * @param array $headers
     */
    public function __construct(
        private readonly int $status,
        private readonly array $headers,
    ) {
    }

    /**
     * Returns the status code.
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Returns the headers as name and value pairs, in sending order.
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Sends the status and headers only, without the Magento response and its page cache plugins.
     *
     * @return void
     */
    public function sendResponse()
    {
        if (headers_sent()) {
            return;
        }
        // The keeper answers before Magento builds its response object, which would start a session.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        http_response_code($this->status);
        foreach ($this->headers as [$name, $value]) {
            // The keeper answers before Magento builds its response object, which would start a session.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            header($name . ': ' . $value, false);
        }
    }
}
