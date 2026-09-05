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
     * @return void
     */
    public function afterExecute(RuleProductIndexer $subject, mixed $result, array $ids): void
    {
        $this->config->markAllProductsForRefresh();
    }

    /**
     * After execute full.
     *
     * @param RuleProductIndexer $subject
     * @param mixed $result
     * @return void
     */
    public function afterExecuteFull(RuleProductIndexer $subject, mixed $result): void
    {
        $this->config->markAllProductsForRefresh();
    }

    /**
     * After execute list.
     *
     * @param RuleProductIndexer $subject
     * @param mixed $result
     * @param array $ids
     * @return void
     */
    public function afterExecuteList(RuleProductIndexer $subject, mixed $result, array $ids): void
    {
        $this->config->markAllProductsForRefresh();
    }

    /**
     * After execute row.
     *
     * @param RuleProductIndexer $subject
     * @param mixed $result
     * @param mixed $id
     * @return void
     */
    public function afterExecuteRow(RuleProductIndexer $subject, mixed $result, mixed $id): void
    {
        $this->config->markAllProductsForRefresh();
    }
}
