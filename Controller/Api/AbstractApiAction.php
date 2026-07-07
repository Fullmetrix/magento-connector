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
    public function __construct(
        protected readonly RequestInterface $request,
        protected readonly JsonFactory $jsonFactory,
        protected readonly HmacRequestVerifier $verifier,
        protected readonly EntityPaginator $paginator,
        protected readonly EntitySerializer $serializer,
        protected readonly Config $config,
    ) {
    }

    protected function unauthorized(): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode(401);
        $result->setData(['success' => false, 'error' => 'unauthorized']);

        return $result;
    }

    protected function json(array $data, int $status = 200): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($status);
        $result->setData($data);

        return $result;
    }

    protected function serializeRow(string $entity, object $row): ?array
    {
        try {
            return match ($entity) {
                'orders' => $this->serializer->serializeOrder($row),
                'customers' => $this->serializer->serializeCustomer($row),
                'products' => $this->serializer->serializeProduct($row),
                'categories' => $this->serializer->serializeCategory($row),
                'coupons' => $this->serializer->serializeCoupon($row),
                'refunds' => $this->serializer->serializeRefund($row),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

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

    protected function isoNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
