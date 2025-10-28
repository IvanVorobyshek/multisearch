<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Model\Api;

use Amasty\Xsearch\Api\Data\ProductRenderInterface;
use Amasty\Xsearch\Api\Data\ProductRenderInterfaceFactory;
use Vendor\Multisearch\Api\MultisearchInterface;
use Vendor\Multisearch\Api\ClientInterface;
use Vendor\Multisearch\Helper\Data;
use Vendor\Xsearch\Api\Data\SearchResultInterface;
use Vendor\Xsearch\Api\Data\SearchResultInterfaceFactory;
use Magento\Catalog\Api\Data\ProductRender\PriceInfoInterfaceFactory;
use Magento\Catalog\Api\Data\ProductRenderExtensionInterfaceFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;

class MultiQuickSearch implements MultisearchInterface
{
    public const CATALOG_SEARCH_URL_PATH = 'catalogsearch/result/?q=';

    public function __construct(
        private readonly SearchResultInterfaceFactory $searchResultFactory,
        private readonly ProductRenderInterfaceFactory $productRenderFactory,
        private readonly PriceInfoInterfaceFactory $priceInfoFactory,
        private readonly ClientInterface $client,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRenderExtensionInterfaceFactory $productRenderExtensionInterfaceFactory,
        private readonly Data $multisearchData,
        private readonly EventManager $eventManager,
    ) {
    }

    public function search(
        string $query,
        int $storeId,
        string $currencyCode,
        ?int $page = null,
        ?int $pageSize = null
    ): SearchResultInterface
    {
        $params = [
            'limit' => (int) $pageSize,
            'offset' => $pageSize * ($page - 1),
            'autocomplete' => 'true'
        ];

        $response = $this->client->search($query, $params);
        $responseData = $response['results'] ?? [];
        $total = $response['total'] ?? 0;
        $itemsData = $responseData['items'] ?? [];

        $items = [];
        foreach ($itemsData as $itemData) {
            /** @var ProductRenderInterface $product */
            $product = $this->productRenderFactory->create();
            $product->setExtensionAttributes($this->productRenderExtensionInterfaceFactory->create());
            $product->setSku($itemData['sku'] ?? '');
            $product->setName($itemData['name'] ?? '');
            $product->setStoreId($storeId);
            $product->setCurrencyCode($currencyCode);
            $product->setIsSalable(($itemData['is_presence'] ?? true) ? '1' : '0');
            $product->setUrl($itemData['url'] ?? '');
            $product->setId($itemData['id'] ?? null);

            $priceInfo = $this->priceInfoFactory->create();
            $price = (float)($itemData['price'] ?? 0);
            $oldPrice = (float)($itemData['oldprice'] ?? 0);

            $priceInfo->setFinalPrice($price);
            $priceInfo->setRegularPrice($oldPrice > $price ? $oldPrice : $price);
            $priceInfo->setSpecialPrice($oldPrice > $price ? $price : 0.0);

            $product->setPriceInfo($priceInfo);
            $product->setImages($itemData['picture'] ? [['url' => $itemData['picture']]] : []);

            $this->eventManager->dispatch(
                'Vendor_multiquick_search', [
                    'product' => $product,
                    'responseItemData' => $itemData,
                    'query' => $query,
                ]
            );

            $items[] = $product;
        }

        $queryData = [];
        $suggestsNumber = $this->multisearchData->getMultisearchSuggestsNumber();
        foreach (array_slice($responseData['suggest'] ?? [], 0, $suggestsNumber) as $suggest) {
            $queryData[] = [
                'name' => $suggest,
                'url' => $this->storeManager->getStore()->getUrl(null, ['_direct' => self::CATALOG_SEARCH_URL_PATH . strip_tags($suggest)])
            ];
        }

        $searchResult = $this->searchResultFactory->create();
        $searchResult->setProducts($items);
        $searchResult->setProductTotalCount($total);
        $searchResult->setProductLastPage((int) ceil($total / $pageSize));
        $searchResult->setQueryData($queryData);

        return $searchResult;
    }
}
