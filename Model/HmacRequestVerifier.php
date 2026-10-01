<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;

class HmacRequestVerifier
{
    private const TIMESTAMP_TOLERANCE_MS = 300000;

    private const READ_PARAMS = [
        'type',
        'entity',
        'sync_type',
        'since',
        'from_id',
        'page',
        'per_page',
        'limit',
        'offset',
        'days',
        'hours',
        Signature::OPERATION_PARAM,
    ];

    /**
     * @param Config $config
     * @param HmacSigner $signer
     * @param Signature $signature
     */
    public function __construct(
        private readonly Config $config,
        private readonly HmacSigner $signer,
        private readonly Signature $signature,
    ) {
    }

    /**
     * Authorizes a signed call, status 200 when allowed.
     *
     * @param RequestInterface $request
     * @param string $operation
     * @param string $body
     * @param bool $command
     * @return array
     */
    public function authorize(RequestInterface $request, string $operation, string $body, bool $command = false): array
    {
        if (!$this->config->isRegistered()) {
            return ['status' => 401, 'nonce' => null, 'reason' => 'not_configured'];
        }
        if ('2' === (string) $request->getHeader(Signature::HEADER_VERSION)) {
            return $this->authorizeV2($request, $operation, $body, $command);
        }
        if (!$this->config->allowsSignatureV1()) {
            return ['status' => 401, 'nonce' => null, 'reason' => 'version'];
        }

        return [
            'status' => $this->verifyV1($request, $command ? $body : '') ? 200 : 401,
            'nonce' => null,
            'reason' => null,
        ];
    }

    /**
     * Builds the response signature headers.
     *
     * @param string $nonce
     * @param string $body
     * @return array
     */
    public function responseHeaders(string $nonce, string $body): array
    {
        return $this->signature->responseHeaders($this->config->getConnectionSecret(), $nonce, $body);
    }

    /**
     * Authorizes a v2 call.
     *
     * @param RequestInterface $request
     * @param string $operation
     * @param string $body
     * @param bool $command
     * @return array
     */
    private function authorizeV2(RequestInterface $request, string $operation, string $body, bool $command): array
    {
        $query = $request instanceof Http
            ? $this->signature->rawQueryString(
                (string) $request->getServer('REQUEST_URI'),
                (string) $request->getServer('QUERY_STRING')
            )
            : '';
        $verdict = $this->signature->verifyRequest(
            $this->config->getConnectionSecret(),
            $this->config->getConnectionCode(),
            [
                'version' => (string) $request->getHeader(Signature::HEADER_VERSION),
                'code' => (string) $request->getHeader(Signature::HEADER_CODE),
                'timestamp' => (string) $request->getHeader(Signature::HEADER_TIMESTAMP),
                'nonce' => (string) $request->getHeader(Signature::HEADER_NONCE),
                'signature' => (string) $request->getHeader(Signature::HEADER_SIGNATURE),
            ],
            $request instanceof Http ? (string) $request->getMethod() : '',
            $query,
            $body,
            $operation,
        );
        if (!$verdict['ok']) {
            return ['status' => 401, 'nonce' => $verdict['nonce'], 'reason' => $verdict['reason']];
        }
        if (!$this->paramsMatchQuery($request, $query)) {
            return ['status' => 401, 'nonce' => $verdict['nonce'], 'reason' => 'params'];
        }
        if ($command) {
            $stored = $this->config->rememberCommandNonce((string) $verdict['nonce']);
            if (Config::NONCE_REPLAYED === $stored) {
                return ['status' => 409, 'nonce' => $verdict['nonce'], 'reason' => 'replay'];
            }
            if (Config::NONCE_STORED !== $stored) {
                return ['status' => 503, 'nonce' => $verdict['nonce'], 'reason' => 'nonce_store'];
            }
        }

        return ['status' => 200, 'nonce' => $verdict['nonce'], 'reason' => null];
    }

    /**
     * Rejects a parameter our controllers read when its value is not the signed one.
     *
     * @param RequestInterface $request
     * @param string $query
     * @return bool
     */
    private function paramsMatchQuery(RequestInterface $request, string $query): bool
    {
        $signed = [];
        foreach ($this->signature->queryPairs($query) as [$name, $value]) {
            $signed[$name] = $value;
        }
        foreach (self::READ_PARAMS as $name) {
            $read = $request->getParam($name);
            if (null === $read) {
                continue;
            }
            if (!\array_key_exists($name, $signed) || $read !== $signed[$name]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verifies a v1 signature.
     *
     * @param RequestInterface $request
     * @param string $body
     * @return bool
     */
    private function verifyV1(RequestInterface $request, string $body): bool
    {
        $code = (string) $request->getHeader('X-Fullmetrix-Connection-Code');
        $signature = (string) $request->getHeader('X-Fullmetrix-Signature');
        $timestamp = (string) $request->getHeader('X-Fullmetrix-Timestamp');

        if ('' === $code || '' === $signature || '' === $timestamp || !ctype_digit($timestamp)) {
            return false;
        }
        if (!hash_equals($this->config->getConnectionCode(), $code)) {
            return false;
        }

        $timestampMs = (int) $timestamp;
        $nowMs = (int) round(microtime(true) * 1000);
        if (abs($nowMs - $timestampMs) > self::TIMESTAMP_TOLERANCE_MS) {
            return false;
        }

        $expected = $this->signer->sign($this->config->getConnectionSecret(), $body, $timestampMs);

        return hash_equals($expected, $signature);
    }
}
