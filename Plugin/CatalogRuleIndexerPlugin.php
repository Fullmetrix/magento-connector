<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Plugin;

use Fullmetrix\Connector\Model\Config;
use Magento\CatalogRule\Model\Indexer\Rule\RuleProductIndexer;

class CatalogRuleIndexerPlugin
{
    /**
     * @param Config $config
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * After execute.
     *
     * @param RuleProductIndexer $subject
     * @param mixed $result
     * @param array $ids
     * @return mixed
     */
    public function afterExecute(RuleProductIndexer $subject, mixed $result, array $ids): mixed
    {
        return $this->markRefresh($result);
    }

    /**
     * After execute full.
     *
     * @param RuleProductIndexer $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterExecuteFull(RuleProductIndexer $subject, mixed $result): mixed
    {
        return $this->markRefresh($result);
    }

    /**
     * After execute list.
     *
     * @param RuleProductIndexer $subject
     * @param mixed $result
     * @param array $ids
     * @return mixed
     */
    public function afterExecuteList(RuleProductIndexer $subject, mixed $result, array $ids): mixed
    {
        return $this->markRefresh($result);
    }

    /**
     * After execute row.
     *
     * @param RuleProductIndexer $subject
     * @param mixed $result
     * @param mixed $id
     * @return mixed
     */
    public function afterExecuteRow(RuleProductIndexer $subject, mixed $result, mixed $id): mixed
    {
        return $this->markRefresh($result);
    }

    /**
     * Asks the next export to resend every product, never failing the indexer.
     *
     * @param mixed $result
     * @return mixed
     */
    private function markRefresh(mixed $result): mixed
    {
        try {
            $this->config->markAllProductsForRefresh();
        // The failure is optional data, the caller keeps going.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }

        return $result;
    }
}
