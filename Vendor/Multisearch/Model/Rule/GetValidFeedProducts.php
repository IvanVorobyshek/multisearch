<?php

namespace Vendor\Multisearch\Model\Rule;

use Amasty\Feed\Api\Data\ValidProductsInterface;
use Amasty\Feed\Model\Feed;
use Amasty\Feed\Model\InventoryResolver;
use Amasty\Feed\Model\Rule\Condition\Sql\Builder;
use Amasty\Feed\Model\Rule\GetValidFeedProducts as AmastyGetValidFeedProducts;
use Amasty\Feed\Model\Rule\RuleFactory;
use Amasty\Feed\Model\ValidProduct\ResourceModel\ValidProduct;
use Vendor\Multisearch\Helper\Data;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product as ModelProduct;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\DB\Select;
use Magento\Store\Model\StoreManagerInterface;

class GetValidFeedProducts extends AmastyGetValidFeedProducts
{

    /**
     * @var Builder
     */
    protected $sqlBuilder;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    public function __construct(
        private RuleFactory $ruleFactory,
        private CollectionFactory $productCollectionFactory,
        Builder $sqlBuilder,
        private InventoryResolver $inventoryResolver,
        StoreManagerInterface $storeManager,
        private Product $productResource,
        private readonly Data $multisearchData,
    ) {
        parent::__construct($ruleFactory, $productCollectionFactory, $sqlBuilder, $inventoryResolver, $storeManager, $productResource);
    }

    public function updateIndex(Feed $model, array $ids = []): void
    {
        $productCollection = $this->prepareCollection($model, $ids);
        $productIdField = 'e.' . $this->productResource->getIdFieldName();
        $productSelect = $this->getProductSelect($productCollection, $productIdField, (int)$model->getEntityId());

        $lastValidProductId = 0;
        $connection = $this->productResource->getConnection();
        while ($lastValidProductId >= 0) {
            $productSelect->where(sprintf('%s > %s', $productIdField, $lastValidProductId));
            $validItemsData = $connection->fetchAll($productSelect);
            if (empty($validItemsData)) {
                break;
            }

            $connection->insertMultiple(
                $this->productResource->getTable(ValidProduct::TABLE_NAME),
                $validItemsData
            );
            $lastValidProduct = array_pop($validItemsData);
            $lastValidProductId = $lastValidProduct[ValidProductsInterface::VALID_PRODUCT_ID] ?? -1;
        }
    }

    private function prepareCollection(Feed $model, array $ids = []): ProductCollection
    {
        $productCollection = $this->productCollectionFactory->create();
        $productCollection->addStoreFilter($model->getStoreId());
        $productCollection->addAttributeToSelect(['visibility', 'type_id']);

        if (!empty($ids)) {
            $productCollection->addAttributeToFilter('entity_id', ['in' => $ids]);
        }

        $this->addExcludeFilters($productCollection, $model);
        $this->addConditionFilters($productCollection, $model);
        if ($this->multisearchData->shouldApplyExtraConditionsForFeed()) {
            $this->addFeedProductsFilters($productCollection);
        }

        return $productCollection;
    }

    private function addFeedProductsFilters(ProductCollection $productCollection): void
    {
        $this->addActiveCategoryFilter($productCollection);

        $connection = $this->productResource->getConnection();

        $relationTable = $this->productResource->getTable('catalog_product_relation');
        $pairs = $connection->fetchAll(
            $connection->select()
                ->from($relationTable, ['parent_id', 'child_id'])
        );

        $childIdsByParent = [];
        $allParentIds = [];

        foreach ($pairs as $row) {
            $childIdsByParent[$row['parent_id']][] = $row['child_id'];
            $allParentIds[$row['parent_id']] = true;
        }

        $invisibleParentIds = [];
        if ($allParentIds) {
            $parentCollection = $this->productCollectionFactory->create();
            $parentCollection->addAttributeToSelect('visibility');
            $parentCollection->addFieldToFilter('entity_id', ['in' => array_keys($allParentIds)]);
            $parentCollection->addAttributeToFilter('type_id', 'configurable');

            foreach ($parentCollection as $parent) {
                if ((int)$parent->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
                    $invisibleParentIds[] = (int)$parent->getId();
                }
            }
        }

        $excludedChildIds = [];
        foreach ($invisibleParentIds as $parentId) {
            $excludedChildIds = array_merge($excludedChildIds, $childIdsByParent[$parentId] ?? []);
        }

        $linkedChildIdsMap = [];
        foreach ($childIdsByParent as $children) {
            foreach ($children as $childId) {
                $linkedChildIdsMap[$childId] = true;
            }
        }

        $productsToRemove = [];

        foreach ($productCollection as $product) {
            $id = (int)$product->getId();
            $visibility = (int)$product->getVisibility();
            $type = $product->getTypeId();

            if (
                $type === 'simple' &&
                $visibility === Visibility::VISIBILITY_NOT_VISIBLE &&
                !isset($linkedChildIdsMap[$id])
            ) {
                $productsToRemove[] = $id;
            }

            if (
                $type === 'configurable' &&
                $visibility === Visibility::VISIBILITY_NOT_VISIBLE
            ) {
                $productsToRemove[] = $id;
            }
        }

        $allExcluded = array_unique(array_merge($productsToRemove, $excludedChildIds));
        if ($allExcluded) {
            $productCollection->addFieldToFilter('entity_id', ['nin' => $allExcluded]);
        }
    }

    private function addActiveCategoryFilter(ProductCollection $collection): void
    {
        $connection = $collection->getConnection();

        $activeCategoryIds = $connection->fetchCol(
            $connection->select()
                ->from(['cat_int' => $this->productResource->getTable('catalog_category_entity_int')], ['row_id'])
                ->joinInner(
                    ['attr' => $this->productResource->getTable('eav_attribute')],
                    'cat_int.attribute_id = attr.attribute_id AND attr.attribute_code = "is_active"',
                    []
                )
                ->where('cat_int.value = ?', 1)
                ->where('cat_int.store_id = ?', 0)
        );

        if (!empty($activeCategoryIds)) {
            $collection->getSelect()->join(
                ['ccp' => $this->productResource->getTable('catalog_category_product')],
                'e.entity_id = ccp.product_id',
                []
            )->where('ccp.category_id IN (?)', $activeCategoryIds)
                ->group('e.entity_id');
        } else {
            $collection->addFieldToFilter('entity_id', -1);
        }
    }

    private function getProductSelect(
        ProductCollection $productCollection,
        string $productIdField,
        int $feedId
    ): Select {
        $productSelect = $productCollection->getSelect();
        $productSelect->reset(Select::COLUMNS)
            ->columns(
                [
                    ValidProductsInterface::ENTITY_ID => new \Zend_Db_Expr('null'),
                    ValidProductsInterface::FEED_ID => new \Zend_Db_Expr($feedId),
                    ValidProductsInterface::VALID_PRODUCT_ID => $productIdField
                ]
            );
        //fix for magento 2.3.2 for big number of products
        $productSelect->reset(Select::ORDER)
            ->distinct()
            ->limit(self::BATCH_SIZE);

        return $productSelect;
    }

    private function addExcludeFilters(ProductCollection $productCollection, Feed $model): void
    {
        $excludedIds = [];
        if ($model->getExcludeDisabled()) {
            $productCollection->addAttributeToFilter(
                'status',
                ['eq' => Status::STATUS_ENABLED]
            );
            if ($model->getExcludeSubDisabled()) {
                $excludedIds = $this->getSubDisabledIds((int)$model->getStoreId());
            }
        }

        if ($model->getExcludeNotVisible()) {
            $productCollection->addAttributeToFilter(
                'visibility',
                ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]
            );
        }

        if ($model->getExcludeOutOfStock()) {
            $outOfStockProductIds = $this->inventoryResolver->getOutOfStockProductIds();
            $excludedIds = array_unique(array_merge($excludedIds, $outOfStockProductIds));
        }

        if (!empty($excludedIds)) {
            $productCollection->addFieldToFilter(
                'entity_id',
                ['nin' => $excludedIds]
            );
        }
    }

    private function addConditionFilters(ProductCollection $productCollection, Feed $model): void
    {
        $conditions = $model->getRule()->getConditions();
        $conditions->collectValidatedAttributes($productCollection);
        $this->sqlBuilder->attachConditionToCollection($productCollection, $conditions);
    }

    private function getSubDisabledIds(int $storeId): array
    {
        $disabledParentProductsSelect = $this->getDisabledParentProductsSelect($storeId);

        $subDisabledProductsCollection = $this->productCollectionFactory->create();
        $subDisabledProductsCollection->getSelect()->join(
            ['rel' => $this->productResource->getTable('catalog_product_relation')],
            'e.entity_id = rel.child_id',
            []
        )->where('rel.parent_id IN (?)', $disabledParentProductsSelect);

        return $subDisabledProductsCollection->getAllIds();
    }

    private function getDisabledParentProductsSelect(int $storeId): Select
    {
        $disabledParentsCollection = $this->productCollectionFactory->create();
        $linkField = $disabledParentsCollection->getProductEntityMetadata()->getLinkField();

        $disabledParentsCollection->addStoreFilter($storeId);
        $disabledParentsCollection->addAttributeToFilter(
            'status',
            ['eq' => Status::STATUS_DISABLED]
        );

        return $disabledParentsCollection->getSelect()
            ->reset(Select::COLUMNS)
            ->columns(['e.' . $linkField])
            ->join(
                ['rel' => $this->productResource->getTable('catalog_product_relation')],
                'rel.parent_id = e.' . $linkField,
                []
            )->distinct();
    }
}
