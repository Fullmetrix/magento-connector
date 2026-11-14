<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class SendOrigin
{
    public const DEFAULT_ORIGIN = 'https://fullmetrix.com';
    public const FALLBACK_PATH = '/assets/js/s.js?k=';

    private const PATTERN = '#^https://([a-z0-9-]{1,20})\.([a-z0-9.-]+)$#';

    /**
     * Returns the origin the script sends to.
     *
     * Either fullmetrix.com, or the published origin when it is exactly one label below the declared domain.
     *
     * @param mixed $candidate
     * @param string $domain
     * @return string
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function accepted(mixed $candidate, string $domain): string
    {
        if (!\is_string($candidate) || self::DEFAULT_ORIGIN === $candidate) {
            return self::DEFAULT_ORIGIN;
        }
        if ('' === $domain || 1 !== preg_match(self::PATTERN, $candidate, $match) || $match[2] !== $domain) {
            return self::DEFAULT_ORIGIN;
        }

        return $candidate;
    }

    /**
     * Returns the declared domain of a store url: its host in lowercase, without a leading www.
     *
     * @param string $url
     * @return string
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function declaredDomain(string $url): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return '';
        }
        $host = strtolower(rtrim($host, '.'));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Returns the remote address of the script.
     *
     * Always on fullmetrix.com so it answers even when a store subdomain is down.
     *
     * @param string $key
     * @return string
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function fallbackUrl(string $key): string
    {
        return self::DEFAULT_ORIGIN . self::FALLBACK_PATH . rawurlencode($key);
    }
}
