<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class CookieReader
{
    private const SIGNAL_COOKIES = [
        'fbp' => '_fbp',
        'fbc' => '_fbc',
        'ga' => '_ga',
        'gclaw' => '_gcl_aw',
        'ttp' => '_ttp',
    ];

    private const SIGNAL_MAX_LENGTH = 200;
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * Returns the visitor id.
     *
     * @return string|null
     */
    public function getVisitorId(): ?string
    {
        return $this->readIdCookie('_kvo_v', self::UUID_V4) ?? $this->readIdCookie('fm_vid');
    }

    /**
     * Returns the session id.
     *
     * @return string|null
     */
    public function getSessionId(): ?string
    {
        return $this->readIdCookie('_kvo_s', self::UUID_V4) ?? $this->readIdCookie('fm_sid');
    }

    /**
     * Returns the advertising browser ids, only the well formed ones.
     *
     * @return array<string, string>
     */
    public function getAdSignals(): array
    {
        $signals = [];
        foreach (self::SIGNAL_COOKIES as $field => $name) {
            // phpcs:ignore Magento2.Security.Superglobal
            $value = $_COOKIE[$name] ?? null;
            if (!\is_string($value) || '' === $value || \strlen($value) > self::SIGNAL_MAX_LENGTH) {
                continue;
            }
            if (1 !== preg_match('#^[A-Za-z0-9._~:|=+/%-]+$#', $value)) {
                continue;
            }
            $signals[$field] = $value;
        }

        return $signals;
    }

    /**
     * Read id cookie.
     *
     * @param string $name
     * @param string $pattern
     * @return string|null
     */
    private function readIdCookie(string $name, string $pattern = '/^[A-Za-z0-9_-]+$/'): ?string
    {
        // The tracker runs before the Magento cookie manager is available.
        // phpcs:ignore Magento2.Security.Superglobal
        $value = $_COOKIE[$name] ?? null;
        if (!\is_string($value) || '' === $value || \strlen($value) > 64) {
            return null;
        }
        if (1 !== preg_match($pattern, $value)) {
            return null;
        }

        return $value;
    }
}
