<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class ConnectorCommandHandler
{
    public function __construct(
        private readonly CouponCommandHandler $couponCommandHandler,
        private readonly Config $config,
    ) {
    }

    public function handle(string $action, array $payload): array
    {
        if ('sync.products.acknowledge' === $action) {
            $this->config->clearAllProductsRefresh();

            return ['success' => true, 'data' => ['acknowledged' => true]];
        }

        return $this->couponCommandHandler->handle($action, $payload);
    }
}
