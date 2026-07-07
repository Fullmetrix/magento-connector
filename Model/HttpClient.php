<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class HttpClient
{
    private static bool $responseFinished = false;

    public static function finishResponse(): void
    {
        if (self::$responseFinished) {
            return;
        }
        self::$responseFinished = true;
        if (\function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        if (\function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    public static function isClientDetached(): bool
    {
        return (self::$responseFinished && \function_exists('fastcgi_finish_request')) || \PHP_SAPI === 'cli';
    }

    public function postJson(string $url, string $body, array $headers, int $timeoutSeconds = 10): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_NOSIGNAL => 1,
        ]);
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => \is_string($responseBody) ? $responseBody : '',
            'error' => $error,
        ];
    }

    public function postFireAndForget(string $url, string $body, array $headers): void
    {
        $detached = self::isClientDetached();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $detached ? 3000 : 800,
            CURLOPT_CONNECTTIMEOUT_MS => $detached ? 2000 : 300,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    }

    public function getJsonFast(string $url, array $headers): array
    {
        $detached = self::isClientDetached();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $detached ? 2000 : 800,
            CURLOPT_CONNECTTIMEOUT_MS => $detached ? 1000 : 300,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => \is_string($responseBody) ? $responseBody : '',
            'error' => $error,
        ];
    }
}
