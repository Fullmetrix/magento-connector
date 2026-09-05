<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class HttpClient
{
    /**
     * @var bool
     */
    private static bool $responseFinished = false;

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
     * @return array
     */
    public function postJson(string $url, string $body, array $headers, int $timeoutSeconds = 10): array
    {
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
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
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
     * Post fire and forget.
     *
     * @param string $url
     * @param string $body
     * @param array $headers
     * @return bool
     */
    public function postFireAndForget(string $url, string $body, array $headers): bool
    {
        $detached = self::isClientDetached();
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
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        curl_close($ch);

        if (false === $result || $status < 200 || $status >= 300) {
            return false;
        }
        $response = json_decode((string) $result, true);
        $error = \is_array($response) ? (string) ($response['error'] ?? '') : '';
        $ignored = \is_array($response) && !empty($response['ignored']);

        return !$ignored && !\in_array($error, ['delete_failed', 'processing_failed'], true);
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
