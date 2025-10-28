<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Plugin;

use Amasty\Feed\Model\Feed;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\UrlInterface;

class AddCategoriesToFeedHeader
{
    public const ROOT_PIMCORE_CATEGORY_NAME = 'Pimcore';
    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    public function afterGetXmlHeader(Feed $subject, string $result): string
    {
        //add categories only there is <categories></categories> tag in Header
        if (strpos($result, '<categories>') !== false && strpos($result, '</categories>') !== false) {
            $storeId = (int)$subject->getStoreId();
            $categoriesXml = $this->generateCategoriesXml($storeId);

            $result = preg_replace(
                '#<categories>\s*</categories>#i',
                $categoriesXml,
                $result
            );
        }

        return $result;
    }

    private function generateCategoriesXml(int $storeId): string
    {
        $rootCategory = $this->categoryCollectionFactory->create()
            ->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'url_key'])
            ->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('level', ['eq' => 1])
            ->addFieldToFilter('name', self::ROOT_PIMCORE_CATEGORY_NAME)
            ->getFirstItem();

        if (!$rootCategory || !$rootCategory->getId()) {
            return "<categories></categories>";
        }

        $rootId = $rootCategory->getId();
        $childCollection = $this->categoryCollectionFactory->create()
            ->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'url_key', 'parent_id'])
            ->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('path', ['like' => "1/{$rootId}/%"])
            ->setOrder('id');

        $xml = "<categories>\n";

        $xml .= sprintf(
            '<category id="%d" url="%s">%s</category>' . "\n",
            $rootId,
            htmlspecialchars($this->urlBuilder->getUrl($rootCategory->getUrl(), ['_direct' => '', '_secure' => true])),
            $rootCategory->getName()
        );

        foreach ($childCollection as $category) {
            $xml .= sprintf(
                '<category id="%d" parentId="%d" url="%s">%s</category>' . "\n",
                $category->getId(),
                $category->getParentId(),
                htmlspecialchars($this->urlBuilder->getUrl($category->getUrl(), ['_direct' => '', '_secure' => true])),
                $category->getName()
            );
        }

        $xml .= "</categories>";
        return $xml;
    }
}
