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

    public function isSupported(string $entity): bool
    {
        return \in_array($entity, self::ENTITIES, true);
    }

    /**
     * @param callable|null $onPage recoit les identifiants de chaque page avant
     *                              qu'elle ne soit parcourue, pour permettre un
     *                              prechargement groupe plutot que du N+1
     */
    public function streamKeyset(string $entity, int $batchSize = 1000, ?string $since = null, ?callable $onPage = null): \Generator
    {
        $lastId = 0;
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

    public function countByEntity(string $entity, ?string $since = null): int
    {
        $collection = $this->buildCollection($entity, $since);

        return null === $collection ? 0 : (int) $collection->getSize();
    }

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
                } catch (\Throwable) {
                }
            }
        }

        return $collection;
    }

    private function buildProductCollection(): object
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($this->storeSettings->getStoreId());
        $collection->addAttributeToSelect('*');
        $collection->addWebsiteFilter($this->storeSettings->getWebsiteId());
        $collection->setFlag('has_stock_status_filter', true);

        return $collection;
    }

    private function buildCategoryCollection(): object
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($this->storeSettings->getStoreId());
        $collection->addAttributeToSelect('*');
        $collection->addFieldToFilter('level', ['gteq' => 2]);
        $collection->addFieldToFilter('path', ['like' => '1/' . $this->storeSettings->getRootCategoryId() . '/%']);

        return $collection;
    }

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

    private function buildOrderCollection(): object
    {
        return $this->orderCollectionFactory->create()
            ->addFieldToFilter('store_id', $this->storeSettings->getStoreId());
    }

    private function buildCustomerCollection(): object
    {
        return $this->customerCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addFieldToFilter('website_id', $this->storeSettings->getWebsiteId());
    }

    private function buildRefundCollection(): object
    {
        return $this->creditmemoCollectionFactory->create()
            ->addFieldToFilter('store_id', $this->storeSettings->getStoreId());
    }

    private function idField(string $entity): string
    {
        return match ($entity) {
            'coupons' => 'coupon_id',
            default => 'entity_id',
        };
    }

    private function updatedField(string $entity): ?string
    {
        return match ($entity) {
            'orders', 'products', 'customers', 'categories', 'refunds' => 'updated_at',
            'coupons' => 'created_at',
            default => null,
        };
    }
}
