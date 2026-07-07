<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class HttpClient
{
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 3000,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_NOSIGNAL => 1,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    public function getJson(string $url, array $headers, int $timeoutSeconds = 10): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
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
}
