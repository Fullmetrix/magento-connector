<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Plugin;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\KeeperResponse;
use Magento\Framework\App\Http as HttpApplication;
use Magento\Framework\App\Request\Http as HttpRequest;

class KeeperEndpoint
{
    public const PATH = '/fullmetrix/keep';

    private const COOKIE_NAME = 'fm_vid';
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const MAX_BODY_BYTES = 64;

    /**
     * @param Config $config
     * @param HttpRequest $request
     */
    public function __construct(
        private readonly Config $config,
        private readonly HttpRequest $request,
    ) {
    }

    /**
     * Answers the cookie keeper call before the area, the front controller and the session exist.
     *
     * Every other request goes through Magento untouched.
     *
     * @param HttpApplication $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundLaunch(HttpApplication $subject, callable $proceed)
    {
        if (!$this->isKeeperRequest()) {
            return $proceed();
        }

        try {
            return $this->answer();
        } catch (\Throwable) {
            return $this->respond(404);
        }
    }

    /**
     * Tells whether the request is a POST on the keeper path below the store base path.
     *
     * The Magento path info is not used: it needs the store resolution of the area.
     *
     * @return bool
     */
    private function isKeeperRequest(): bool
    {
        if ('POST' !== $this->request->getMethod()) {
            return false;
        }
        $uri = (string) $this->request->getRequestUri();
        if (!str_contains($uri, self::PATH)) {
            return false;
        }
        $queryAt = strpos($uri, '?');
        $path = false === $queryAt ? $uri : substr($uri, 0, $queryAt);

        return rtrim((string) $this->request->getBaseUrl(), '/') . self::PATH === $path;
    }

    /**
     * Checks the call and sets the visitor cookie again with the same value.
     *
     * @return KeeperResponse
     */
    private function answer(): KeeperResponse
    {
        $settings = $this->config->getTagSettings();
        if (!$settings['keeper'] || !$this->config->isActive() || !$this->config->isTrackerEnabled()) {
            return $this->respond(404);
        }

        $fetchSite = $this->request->getHeader('Sec-Fetch-Site');
        if (\is_string($fetchSite) && '' !== $fetchSite && 'same-origin' !== $fetchSite) {
            return $this->respond(403);
        }

        $length = $this->request->getHeader('Content-Length');
        if (\is_string($length) && '' !== $length && (int) $length > self::MAX_BODY_BYTES) {
            return $this->respond(400);
        }

        $values = $this->cookieValues((string) $this->request->getServer('HTTP_COOKIE', ''));
        if (\count($values) > 1) {
            return $this->respond(409);
        }
        $visitorId = $values[0] ?? '';
        if (1 !== preg_match(self::UUID_V4_PATTERN, $visitorId)
            || !hash_equals($visitorId, (string) $this->request->getContent())
        ) {
            return $this->respond(400);
        }

        $cookie = self::COOKIE_NAME . '=' . $visitorId
            . '; Max-Age=' . ($settings['maxAgeDays'] * 86400)
            . '; Path=/; SameSite=Lax'
            . ($this->request->isSecure() ? '; Secure' : '');

        return $this->respond(204, [['X-Fm-Keeper', '1'], ['Set-Cookie', $cookie]]);
    }

    /**
     * Returns every value sent for the visitor cookie, read from the raw header so duplicates are seen.
     *
     * @param string $header
     * @return array
     */
    private function cookieValues(string $header): array
    {
        $values = [];
        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            if (self::COOKIE_NAME === trim($parts[0])) {
                $values[] = trim($parts[1] ?? '');
            }
        }

        return $values;
    }

    /**
     * Builds an empty response that no cache keeps.
     *
     * @param int $status
     * @param array $extraHeaders
     * @return KeeperResponse
     */
    private function respond(int $status, array $extraHeaders = []): KeeperResponse
    {
        return new KeeperResponse($status, array_merge([
            ['Cache-Control', 'no-store, private'],
            ['CDN-Cache-Control', 'no-store'],
            ['X-Robots-Tag', 'noindex'],
        ], $extraHeaders));
    }
}
