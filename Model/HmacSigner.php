<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class HmacSigner
{
    /**
     * @param Config $config
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Signs the.
     *
     * @param string $secret
     * @param string $body
     * @param int $timestampMs
     * @return string
     */
    public function sign(string $secret, string $body, int $timestampMs): string
    {
        return hash_hmac('sha256', $timestampMs . '.' . $body, $secret);
    }

    /**
     * Builds the headers.
     *
     * @param string $body
     * @param string|null $versionPrefix
     * @return array
     */
    public function buildHeaders(string $body = '', ?string $versionPrefix = null): array
    {
        $timestampMs = (int) round(microtime(true) * 1000);
        $secret = $this->config->getConnectionSecret();
        $version = null !== $versionPrefix
            ? $versionPrefix . Config::VERSION
            : Config::VERSION;

        return [
            'Content-Type: application/json',
            'X-Fullmetrix-Connection-Code: ' . $this->config->getConnectionCode(),
            'X-Fullmetrix-Signature: ' . $this->sign($secret, $body, $timestampMs),
            'X-Fullmetrix-Timestamp: ' . $timestampMs,
            'X-Fullmetrix-Plugin-Version: ' . $version,
        ];
    }
}
