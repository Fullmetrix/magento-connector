<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class CookieReader
{
    public function getVisitorId(): ?string
    {
        return $this->readIdCookie('fm_vid');
    }

    public function getSessionId(): ?string
    {
        return $this->readIdCookie('fm_sid');
    }

    private function readIdCookie(string $name): ?string
    {
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
