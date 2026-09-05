<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class CookieReader
{
    /**
     * Returns the visitor id.
     *
     * @return string|null
     */
    public function getVisitorId(): ?string
    {
        return $this->readIdCookie('fm_vid');
    }

    /**
     * Returns the session id.
     *
     * @return string|null
     */
    public function getSessionId(): ?string
    {
        return $this->readIdCookie('fm_sid');
    }

    /**
     * Read id cookie.
     *
     * @param string $name
     * @return string|null
     */
    private function readIdCookie(string $name): ?string
    {
        // The tracker runs before the Magento cookie manager is available.
        // phpcs:ignore Magento2.Security.Superglobal
        $value = $_COOKIE[$name] ?? null;
        if (!\is_string($value) || '' === $value || \strlen($value) > 64) {
            return null;
        }
        if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return null;
        }

        return $value;
    }
}
