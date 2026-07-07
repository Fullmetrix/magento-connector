<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\RequestInterface;

class HmacRequestVerifier
{
    private const TIMESTAMP_TOLERANCE_MS = 300000;

    public function __construct(
        private readonly Config $config,
        private readonly HmacSigner $signer,
    ) {
    }

    public function verify(RequestInterface $request, string $body = ''): bool
    {
        if (!$this->config->isRegistered()) {
            return false;
        }

        $code = (string) $request->getHeader('X-Fullmetrix-Connection-Code');
        $signature = (string) $request->getHeader('X-Fullmetrix-Signature');
        $timestamp = (string) $request->getHeader('X-Fullmetrix-Timestamp');

        if ('' === $code || '' === $signature || '' === $timestamp || !ctype_digit($timestamp)) {
            return false;
        }
        if (!hash_equals($this->config->getConnectionCode(), $code)) {
            return false;
        }

        $timestampMs = (int) $timestamp;
        $nowMs = (int) round(microtime(true) * 1000);
        if (abs($nowMs - $timestampMs) > self::TIMESTAMP_TOLERANCE_MS) {
            return false;
        }

        $expected = $this->signer->sign($this->config->getConnectionSecret(), $body, $timestampMs);

        return hash_equals($expected, $signature);
    }
}
