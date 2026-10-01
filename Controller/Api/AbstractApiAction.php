<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Api;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\EntityPaginator;
use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\HmacRequestVerifier;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

abstract class AbstractApiAction implements \Magento\Framework\App\ActionInterface
{
    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param HmacRequestVerifier $verifier
     * @param EntityPaginator $paginator
     * @param EntitySerializer $serializer
     * @param Config $config
     */
    public function __construct(
        protected readonly RequestInterface $request,
        protected readonly JsonFactory $jsonFactory,
        protected readonly HmacRequestVerifier $verifier,
        protected readonly EntityPaginator $paginator,
        protected readonly EntitySerializer $serializer,
        protected readonly Config $config,
    ) {
    }

    /**
     * Nonce of the verified v2 request, used to sign the JSON response.
     *
     * @var string|null
     */
    protected ?string $responseNonce = null;

    /**
     * Authorizes the call, returns the error result or null when allowed.
     *
     * @param string $operation
     * @param bool $command
     * @param string|null $body
     * @return Json|null
     */
    protected function guard(string $operation, bool $command = false, ?string $body = null): ?Json
    {
        $decision = $this->verifier->authorize($this->request, $operation, $body ?? $this->rawBody(), $command);
        $this->responseNonce = $decision['nonce'];
        if (200 === $decision['status']) {
            return null;
        }

        return $this->json([
            'success' => false,
            'error' => match ($decision['status']) {
                409 => 'replayed_request',
                503 => 'unavailable',
                default => 'unauthorized',
            },
            'reason' => $decision['reason'],
            'server_time' => time(),
        ], $decision['status']);
    }

    /**
     * Reads the raw request body.
     *
     * @return string
     */
    protected function rawBody(): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return (string) file_get_contents('php://input');
    }

    /**
     * Builds a JSON result.
     *
     * @param array $data
     * @param int $status
     * @return Json
     */
    protected function json(array $data, int $status = 200): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($status);
        $body = (string) json_encode($data, \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $result->setJsonData($body);
        $result->setHeader('X-Fullmetrix-Plugin-Version', Config::VERSION, true);
        if (null !== $this->responseNonce) {
            foreach ($this->verifier->responseHeaders($this->responseNonce, $body) as $name => $value) {
                $result->setHeader($name, $value, true);
            }
        }

        return $result;
    }

    /**
     * Serializes the row.
     *
     * @param string $entity
     * @param object $row
     * @return array|null
     */
    protected function serializeRow(string $entity, object $row): ?array
    {
        return match ($entity) {
            'orders' => $this->serializer->serializeOrder($row),
            'customers' => $this->serializer->serializeCustomer($row),
            'products' => $this->serializer->serializeProduct($row),
            'categories' => $this->serializer->serializeCategory($row),
            'coupons' => $this->serializer->serializeCoupon($row),
            'refunds' => $this->serializer->serializeRefund($row),
            default => null,
        };
    }

    /**
     * Line type.
     *
     * @param string $entity
     * @return string
     */
    protected function lineType(string $entity): string
    {
        return match ($entity) {
            'orders' => 'order',
            'customers' => 'customer',
            'products' => 'product',
            'categories' => 'category',
            'coupons' => 'coupon',
            'refunds' => 'refund',
            default => $entity,
        };
    }

    /**
     * Parses the since.
     *
     * @return string|null
     */
    protected function parseSince(): ?string
    {
        $since = $this->request->getParam('since');
        if (!\is_string($since) || '' === $since) {
            return null;
        }
        $syncType = (string) $this->request->getParam('sync_type', 'full');
        if ('incremental' !== $syncType) {
            return null;
        }

        return $since;
    }

    /**
     * Iso now.
     *
     * @return string
     */
    protected function isoNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
