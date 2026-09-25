<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class HttpClient
{
    public const DELIVERY_DELIVERED = 'delivered';
    public const DELIVERY_REJECTED = 'rejected';
    public const DELIVERY_UNREACHABLE = 'unreachable';

    private const UNREACHABLE_STATUSES = [0, 429, 502, 503, 504, 520, 521, 522, 523, 524, 525, 526, 527, 530];

    /**
     * @var bool
     */
    private static bool $responseFinished = false;

    /**
     * @var mixed
     */
    private mixed $handle = null;

    /**
     * Finish response.
     *
     * @return void
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function finishResponse(): void
    {
        if (self::$responseFinished) {
            return;
        }
        self::$responseFinished = true;
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if (\function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
    }

    /**
     * Tells whether the client detached.
     *
     * @return bool
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function isClientDetached(): bool
    {
        return (self::$responseFinished && \function_exists('fastcgi_finish_request')) || \PHP_SAPI === 'cli';
    }

    /**
     * Post json.
     *
     * @param string $url
     * @param string $body
     * @param array $headers
     * @param int $timeoutSeconds
     * @param int|null $connectTimeoutMs
     * @param int|null $totalTimeoutMs
     * @return array
     */
    public function postJson(
        string $url,
        string $body,
        array $headers,
        int $timeoutSeconds = 10,
        ?int $connectTimeoutMs = null,
        ?int $totalTimeoutMs = null
    ): array {
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $ch = curl_init($url);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $totalTimeoutMs ?? ($timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs ?? 5000,
            CURLOPT_NOSIGNAL => true,
        ]);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $responseBody = curl_exec($ch);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $error = curl_error($ch);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        curl_close($ch);

        return [
            'status' => $status,
            'body' => \is_string($responseBody) ? $responseBody : '',
            'error' => $error,
        ];
    }

    /**
     * Posts one delivery and tells whether it was taken, refused, or never reached the API.
     *
     * @param string $url
     * @param string $body
     * @param array $headers
     * @return string One of the DELIVERY_* constants.
     */
    public function deliver(string $url, string $body, array $headers): string
    {
        $detached = self::isClientDetached();
        $ch = $this->reusableHandle();
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $detached ? 3000 : 800,
            CURLOPT_CONNECTTIMEOUT_MS => $detached ? 2000 : 300,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $result = curl_exec($ch);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $outcome = $this->classify($result, $status);
        if (self::DELIVERY_UNREACHABLE === $outcome) {
            $this->closeReusableHandle();
        }

        return $outcome;
    }

    /**
     * Classifies an API answer.
     *
     * Only a transport failure, a gateway error (502, 503, 504, and the Cloudflare origin errors 520 to 527
     * and 530) or a rate limit (429) means the API
     * cannot be reached: nothing is counted and the run stops. A 500 or any other error is a refusal
     * of this one row, which is counted and backed off while the next rows keep going.
     *
     * @param string|false $body
     * @param int $status
     * @return string One of the DELIVERY_* constants.
     */
    public function classify(string|false $body, int $status): string
    {
        if (false === $body || \in_array($status, self::UNREACHABLE_STATUSES, true)) {
            return self::DELIVERY_UNREACHABLE;
        }
        if ($status < 200 || $status >= 300) {
            return self::DELIVERY_REJECTED;
        }
        $response = json_decode($body, true);
        $error = \is_array($response) ? (string) ($response['error'] ?? '') : '';
        $ignored = \is_array($response) && !empty($response['ignored']);

        return $ignored || \in_array($error, ['delete_failed', 'processing_failed'], true)
            ? self::DELIVERY_REJECTED
            : self::DELIVERY_DELIVERED;
    }

    /**
     * Returns a cURL handle kept between calls, so a batch of webhooks reuses one connection.
     *
     * @return mixed
     */
    private function reusableHandle(): mixed
    {
        if (null === $this->handle) {
            // The connector needs the raw call here, the Magento wrapper does not cover it.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $this->handle = curl_init();

            return $this->handle;
        }
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        curl_reset($this->handle);

        return $this->handle;
    }

    /**
     * Drops the kept handle after a failure, the next call opens a fresh connection.
     *
     * @return void
     */
    private function closeReusableHandle(): void
    {
        if (null !== $this->handle) {
            // The connector needs the raw call here, the Magento wrapper does not cover it.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            curl_close($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Returns the json fast.
     *
     * @param string $url
     * @param array $headers
     * @return array
     */
    public function getJsonFast(string $url, array $headers): array
    {
        $detached = self::isClientDetached();
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $ch = curl_init($url);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $detached ? 2000 : 800,
            CURLOPT_CONNECTTIMEOUT_MS => $detached ? 1000 : 300,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $responseBody = curl_exec($ch);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $error = curl_error($ch);
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        curl_close($ch);

        return [
            'status' => $status,
            'body' => \is_string($responseBody) ? $responseBody : '',
            'error' => $error,
        ];
    }
}
