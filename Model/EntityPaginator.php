<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory as CreditmemoCollectionFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as CouponCollectionFactory;

class EntityPaginator
{
    public const ENTITIES = ['orders', 'customers', 'products', 'categories', 'coupons', 'refunds'];

    /**
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param CustomerCollectionFactory $customerCollectionFactory
     * @param ProductCollectionFactory $productCollectionFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param CouponCollectionFactory $couponCollectionFactory
     * @param CreditmemoCollectionFactory $creditmemoCollectionFactory
     * @param StoreSettingsProvider $storeSettings
     * @param Config $config
     */
    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly CustomerCollectionFactory $customerCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CouponCollectionFactory $couponCollectionFactory,
        private readonly CreditmemoCollectionFactory $creditmemoCollectionFactory,
        private readonly StoreSettingsProvider $storeSettings,
        private readonly Config $config,
    ) {
    }

    /**
     * Tells whether the supported.
     *
     * @param string $entity
     * @return bool
     */
    public function isSupported(string $entity): bool
    {
        return \in_array($entity, self::ENTITIES, true);
    }

    /**
     * Walks an entity by keyset pagination and yields every row.
     *
     * The $onPage callback receives the identifiers of each page before it is walked,
     * so related data can be preloaded in one query instead of one query per row.
     *
     * @param string $entity
     * @param int $batchSize
     * @param string|null $since
     * @param callable|null $onPage
     * @param int $fromId
     * @return \Generator
     */
    public function streamKeyset(
        string $entity,
        int $batchSize = 1000,
        ?string $since = null,
        ?callable $onPage = null,
        int $fromId = 0
    ): \Generator {
        $lastId = $fromId;
        while (true) {
            $collection = $this->buildCollection($entity, $since);
            if (null === $collection) {
                return;
            }
            $idField = $this->idField($entity);
            $collection->addFieldToFilter($idField, ['gt' => $lastId]);
            $collection->setOrder($idField, 'ASC');
            $collection->setPageSize($batchSize);
            if ('products' === $entity && method_exists($collection, 'addMediaGalleryData')) {
                try {
                    $collection->addMediaGalleryData();
                // The failure is optional data, the caller keeps going.
                // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
                } catch (\Throwable) {
                }
            }

            $pageRows = [];
            foreach ($collection as $row) {
                $pageRows[] = $row;
            }
            if (null !== $onPage && [] !== $pageRows) {
                $ids = [];
                foreach ($pageRows as $row) {
                    $ids[] = (int) $row->getData($idField);
                }
                $onPage($ids);
            }

            $count = 0;
            foreach ($pageRows as $row) {
                yield $row;
                $lastId = (int) $row->getData($idField);
                ++$count;
            }

            if ($count < $batchSize) {
                return;
            }
        }
    }

    /**
     * Counts the by entity.
     *
     * @param string $entity
     * @param string|null $since
     * @return int
     */
    public function countByEntity(string $entity, ?string $since = null): int
    {
        $collection = $this->buildCollection($entity, $since);

        return null === $collection ? 0 : (int) $collection->getSize();
    }

    /**
     * Recently updated.
     *
     * @param string $entity
     * @param int $days
     * @param int $hours
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function recentlyUpdated(string $entity, int $days, int $hours, int $limit, int $offset): array
    {
        $collection = $this->buildCollection($entity, null);
        if (null === $collection) {
            return [];
        }
        $updatedField = $this->updatedField($entity);
        if (null === $updatedField) {
            return [];
        }
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d days -%d hours', $days, $hours))
            ->format('Y-m-d H:i:s');
        $collection->addFieldToFilter($updatedField, ['gteq' => $cutoff]);
        $collection->setOrder($updatedField, 'DESC');
        $collection->getSelect()->limit(min(500000, max(1, $limit)), max(0, $offset));

        $result = [];
        $idField = $this->idField($entity);
        foreach ($collection as $row) {
            $updatedAt = (string) $row->getData($updatedField);
            $result[] = [
                'id' => (int) $row->getData($idField),
                'last_updated' => '' !== $updatedAt ? (int) strtotime($updatedAt) : 0,
            ];
        }

        return $result;
    }

    /**
     * Builds the collection.
     *
     * @param string $entity
     * @param string|null $since
     * @return object|null
     */
    private function buildCollection(string $entity, ?string $since): ?object
    {
        $collection = match ($entity) {
            'orders' => $this->buildOrderCollection(),
            'customers' => $this->buildCustomerCollection(),
            'products' => $this->buildProductCollection(),
            'categories' => $this->buildCategoryCollection(),
            'coupons' => $this->buildCouponCollection(),
            'refunds' => $this->buildRefundCollection(),
            default => null,
        };
        if (null === $collection) {
            return null;
        }

        $skipSince = 'products' === $entity && $this->config->shouldRefreshAllProducts();
        if (!$skipSince && null !== $since && '' !== $since) {
            $updatedField = $this->updatedField($entity);
            if (null !== $updatedField) {
                try {
                    $sinceUtc = (new \DateTimeImmutable($since))
                        ->setTimezone(new \DateTimeZone('UTC'))
                        ->format('Y-m-d H:i:s');
                    $collection->addFieldToFilter($updatedField, ['gteq' => $sinceUtc]);
                // The failure is optional data, the caller keeps going.
                // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
                } catch (\Throwable) {
                }
            }
        }

        return $collection;
    }

    /**
     * Builds the product collection.
     *
     * @return object
     */
    private function buildProductCollection(): object
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($this->storeSettings->getStoreId());
        $collection->addAttributeToSelect('*');
        $collection->addWebsiteFilter($this->storeSettings->getWebsiteId());
        $collection->setFlag('has_stock_status_filter', true);

        return $collection;
    }

    /**
     * Builds the category collection.
     *
     * @return object
     */
    private function buildCategoryCollection(): object
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($this->storeSettings->getStoreId());
        $collection->addAttributeToSelect('*');
        $collection->addFieldToFilter('level', ['gteq' => 2]);
        $collection->addFieldToFilter('path', ['like' => '1/' . $this->storeSettings->getRootCategoryId() . '/%']);

        return $collection;
    }

    /**
     * Builds the coupon collection.
     *
     * @return object
     */
    private function buildCouponCollection(): object
    {
        $collection = $this->couponCollectionFactory->create();
        $collection->getSelect()->joinInner(
            ['fullmetrix_rule_website' => $collection->getTable('salesrule_website')],
            'main_table.rule_id = fullmetrix_rule_website.rule_id',
            []
        )->where('fullmetrix_rule_website.website_id = ?', $this->storeSettings->getWebsiteId());

        return $collection;
    }

    /**
     * Builds the order collection.
     *
     * @return object
     */
    private function buildOrderCollection(): object
    {
        return $this->orderCollectionFactory->create()
            ->addFieldToFilter('store_id', $this->storeSettings->getStoreId());
    }

    /**
     * Builds the customer collection.
     *
     * @return object
     */
    private function buildCustomerCollection(): object
    {
        return $this->customerCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addFieldToFilter('website_id', $this->storeSettings->getWebsiteId());
    }

    /**
     * Builds the refund collection.
     *
     * @return object
     */
    private function buildRefundCollection(): object
    {
        return $this->creditmemoCollectionFactory->create()
            ->addFieldToFilter('store_id', $this->storeSettings->getStoreId());
    }

    /**
     * Returns the keyset column value of a row, which is not always the identifier the payload exposes.
     *
     * @param string $entity
     * @param mixed $row
     * @return int|null
     */
    public function cursorValue(string $entity, $row)
    {
        $field = $this->idField($entity);
        $value = method_exists($row, 'getData') ? $row->getData($field) : null;

        return null === $value ? null : (int) $value;
    }

    /**
     * Id field.
     *
     * @param string $entity
     * @return string
     */
    private function idField(string $entity): string
    {
        return match ($entity) {
            'coupons' => 'coupon_id',
            default => 'entity_id',
        };
    }

    /**
     * Updated field.
     *
     * @param string $entity
     * @return string|null
     */
    private function updatedField(string $entity): ?string
    {
        return match ($entity) {
            'orders', 'products', 'customers', 'categories', 'refunds' => 'updated_at',
            'coupons' => 'created_at',
            default => null,
        };
    }
}
