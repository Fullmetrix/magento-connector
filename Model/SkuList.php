<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class SkuList
{
    /**
     * Returns the distinct SKUs of a list of items, as queue identifiers.
     *
     * @param array $items
     * @return array
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function fromItems(array $items): array
    {
        $skus = [];
        foreach ($items as $item) {
            if (\is_object($item) && method_exists($item, 'getSku')) {
                $sku = trim((string) $item->getSku());
                if ('' !== $sku) {
                    $skus['sku:' . $sku] = true;
                }
            }
        }

        return array_keys($skus);
    }
}
