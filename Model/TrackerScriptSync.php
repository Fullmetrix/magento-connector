<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class TrackerScriptSync
{
    public const OUTCOME_LOCAL = 'local';
    public const OUTCOME_REMOTE = 'remote';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_PAUSED = 'paused';
    public const OUTCOME_SKIPPED = 'skipped';

    public const ORIGIN_PATTERN = '#^https://([a-z0-9-]+\.)?fullmetrix\.com$#';

    private const VERSION_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
    private const MAX_SCRIPT_BYTES = 2097152;
    private const TOTAL_TIMEOUT_MS = 3000;
    private const CONNECT_TIMEOUT_MS = 1500;
    private const FIRST_PAUSE_SECONDS = 900;
    private const MAX_PAUSE_SECONDS = 21600;

    /**
     * @param Config $config
     * @param ApiClient $apiClient
     * @param HttpClient $httpClient
     * @param LocalScript $localScript
     */
    public function __construct(
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly HttpClient $httpClient,
        private readonly LocalScript $localScript,
    ) {
    }

    /**
     * Refreshes the tracker file served from the store, from the cron only.
     *
     * @return string One of the OUTCOME_* constants.
     */
    public function run(): string
    {
        if (!$this->config->isActive() || !$this->apiClient->isTrackerEnabled()) {
            return self::OUTCOME_SKIPPED;
        }

        $breaker = $this->config->getTrackerSync();
        $now = time();
        if ((int) ($breaker['failures'] ?? 0) > 0 && (int) ($breaker['retry_at'] ?? 0) > $now) {
            return self::OUTCOME_PAUSED;
        }

        try {
            $outcome = $this->refresh();
        } catch (\Throwable) {
            $outcome = self::OUTCOME_FAILED;
        }

        if (self::OUTCOME_FAILED === $outcome) {
            $failures = (int) ($breaker['failures'] ?? 0) + 1;
            $pause = min(self::MAX_PAUSE_SECONDS, self::FIRST_PAUSE_SECONDS * (2 ** min($failures - 1, 10)));
            $this->config->saveTrackerSync(['failures' => $failures, 'retry_at' => $now + $pause]);

            return $outcome;
        }

        if ([] !== $breaker) {
            $this->config->saveTrackerSync([]);
        }

        return $outcome;
    }

    /**
     * Downloads, checks and installs the bundle the server chose for this store.
     *
     * @return string
     */
    private function refresh(): string
    {
        $response = $this->httpClient->getJson(
            $this->config->getAppOrigin() . '/api/tracker/bundle?key='
                . rawurlencode($this->config->getConnectionCode()),
            [
                'Accept: application/json',
                'X-Fullmetrix-Plugin-Version: ' . Config::VERSION,
            ],
            self::TOTAL_TIMEOUT_MS,
            self::CONNECT_TIMEOUT_MS,
            true
        );
        $bundle = json_decode((string) $response['body'], true);

        if (404 === $response['status'] && \is_array($bundle) && 'unknown_key' === ($bundle['error'] ?? null)) {
            return $this->switchRemote();
        }
        if (200 !== $response['status'] || !\is_array($bundle)) {
            return self::OUTCOME_FAILED;
        }
        $tagSettings = TagSettings::fromBundle($bundle, $this->config->getTagSettings());
        if (null !== $tagSettings) {
            $this->config->saveTagSettings($tagSettings);
        }
        if (false === ($bundle['local'] ?? null)) {
            return $this->switchRemote();
        }

        $version = $bundle['version'] ?? null;
        $content = $bundle['content'] ?? null;
        $sha256 = $bundle['sha256'] ?? null;
        $origin = $bundle['origin'] ?? null;
        if (!\is_string($version) || 1 !== preg_match(self::VERSION_PATTERN, $version)
            || !\is_string($content) || '' === $content || \strlen($content) > self::MAX_SCRIPT_BYTES
            || !\is_string($sha256) || !hash_equals(hash('sha256', $content), strtolower($sha256))
            || !\is_string($origin) || 1 !== preg_match(self::ORIGIN_PATTERN, $origin)
        ) {
            return self::OUTCOME_FAILED;
        }

        $path = $this->localScript->install($version, $content, strtolower($sha256));
        $state = $this->config->getLocalScript();
        $current = (string) ($state['file'] ?? '');
        $previous = (string) ($state['previous'] ?? '');
        if ($current !== $path) {
            $previous = $current;
            try {
                $this->localScript->markCurrent($path);
            // The date only helps the cleanup, the file is served either way.
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            } catch (\Throwable) {
            }
        }

        $this->config->saveLocalScript([
            'file' => $path,
            'version' => $version,
            'sha256' => strtolower($sha256),
            'origin' => $origin,
            'previous' => $previous,
            'remote' => false,
            'updated_at' => time(),
        ]);

        try {
            $this->localScript->prune($path, $previous, $this->config->getPageCacheTtl());
        // A failed cleanup is retried on the next run and never blocks the served file.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }

        return self::OUTCOME_LOCAL;
    }

    /**
     * Puts the remote tag back while keeping the files that cached pages still reference.
     *
     * @return string
     */
    private function switchRemote(): string
    {
        $state = $this->config->getLocalScript();
        $state['remote'] = true;
        $state['updated_at'] = time();
        $this->config->saveLocalScript($state);

        return self::OUTCOME_REMOTE;
    }
}
