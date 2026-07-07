<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class HmacSigner
{
    public function __construct(private readonly Config $config)
    {
    }

    public function sign(string $secret, string $body, int $timestampMs): string
    {
        return hash_hmac('sha256', $timestampMs . '.' . $body, $secret);
    }

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
