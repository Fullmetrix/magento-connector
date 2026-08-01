<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Plugin;

use Fullmetrix\Connector\Model\Config;
use Magento\CatalogRule\Model\Indexer\Rule\RuleProductIndexer;

class CatalogRuleIndexerPlugin
{
    public function __construct(private readonly Config $config)
    {
    }

    public function afterExecute(RuleProductIndexer $subject, mixed $result, array $ids): void
    {
        $this->config->markAllProductsForRefresh();
    }

    public function afterExecuteFull(RuleProductIndexer $subject, mixed $result): void
    {
        $this->config->markAllProductsForRefresh();
    }

    public function afterExecuteList(RuleProductIndexer $subject, mixed $result, array $ids): void
    {
        $this->config->markAllProductsForRefresh();
    }

    public function afterExecuteRow(RuleProductIndexer $subject, mixed $result, mixed $id): void
    {
        $this->config->markAllProductsForRefresh();
    }
}
