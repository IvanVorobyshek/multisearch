<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Model\Search;

use Amasty\ElasticSearch\Model\Search\GetResponse;
use Vendor\Multisearch\Api\ClientInterface;
use Vendor\Multisearch\Helper\DataClient;
use Vendor\Multisearch\Model\Search\FiltersStorage;
use Vendor\Multisearch\Model\Search\TotalStorage;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface as Request;
use Magento\Framework\Search\AdapterInterface;
use Magento\Framework\Search\RequestInterface;
use Magento\Framework\Search\Response\QueryResponse;

class Adapter implements AdapterInterface
{
    private ?array $cachedSearchFilters = null;

    public function __construct(
        private readonly GetResponse $elasticResponse,
        private readonly ClientInterface $client,
        private readonly Request $request,
        private readonly DataClient $dataClient,
        private readonly TotalStorage $totalStorage,
        private readonly FiltersStorage $filtersStorage,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
    ) {
    }

    public function query(RequestInterface $request): QueryResponse
    {
        $queryText = $this->getQueryText();

        if (mb_strlen($queryText) <= 1) {
            return $this->elasticResponse->execute([], [], 0);
        }

        $customerId = $this->dataClient->getCustomerIdFromCookie();

        $params = [
            'uid' => $customerId,
            'fields' => 'true',
            'categories' => 0,
            'limit' => $this->dataClient->getPageSize(),
            'offset' => $this->dataClient->getOffset(),
            'group' => 'true',
        ];

        $params['filters'] = json_encode($this->buildMultisearchFilters(), JSON_UNESCAPED_UNICODE);
        if ($params['filters'] === '[]') {
            $params['filters'] = 'true';
        }

        $order = $this->request->getParam('product_list_order');
        $sortMap = [
            'price_low_to_high' => 'price.asc',
            'price_high_to_low' => 'price.desc',
        ];
        if (isset($sortMap[$order])) {
            $params['sort'] = $sortMap[$order];
        }

        $response = $this->client->search($queryText, $params);

        $items = $response['results']['items'] ?? [];
        $total = $response['total'] ?? 0;
        $this->totalStorage->set($total);
        $filters = $response['results']['filters'] ?? [];

        $documents = [];
        $score = count($items);

        foreach ($items as $item) {
            $documents[] = [
                '_id' => $item['id'],
                '_score' => $score--,
            ];
        }

        $aggregations = [];
        $filtersToStorage = $this->createFilters($filters);

        $this->filtersStorage->set($filtersToStorage);

        return $this->elasticResponse->execute($documents, $aggregations, $total);
    }

    private function createFilters(array $filters): array
    {
        $filtersToStorage = [];

        foreach ($filters ?? [] as $filter) {
            $items = [];

            if ($filter['id'] === 'price') {
                $filtersToStorage['price'][] = [
                    'value' => $filter['name'],
                    'label' => $filter['name'],
                    'count' => 1,
                    'price_from' => $filter['range']['from'] ?? 0,
                    'price_to' => $filter['range']['to'] ?? 0,
                    'price_min' => $filter['range']['min'] ?? 0,
                    'price_max' => $filter['range']['max'] ?? 0,
                ];

                continue;
            }

            if ($filter['id'] === 'brand') {
                foreach ($filter['values'] as $option) {
                    $items[] = [
                        'value' => (string) $option['id'],
                        'label' => (string) $option['name'],
                        'count' => (string) $option['count']
                    ];
                }
                $filtersToStorage[$filter['id']] = $items;

                continue;
            }

            if (lcfirst($filter['name']) === 'age') {
                foreach ($filter['values'] as $option) {
                    $items[] = [
                        'value' => (int) $option['id'],
                        'admin_label' => (string) $option['name'],
                        'store_label' => (string) $option['label'],
                        'label' => (string) $option['name'],
                        'count' => (string) $option['count']
                    ];
                }

                usort($items, static function ($a, $b) {
                    return $a['value'] <=> $b['value'];
                });
                $filtersToStorage['age'] = $items;

                continue;
            }

            if ($filter['name'] === 'color_hex') {
                foreach ($filter['values'] as $option) {
                    $items[] = [
                        'value' => (string) $option['id'],
                        'label' => (string) $option['name'],
                        'count' => (string) $option['count'],
                        'color' => '#808080'
                    ];
                }
                $filtersToStorage[$filter['name']] = $items;

                continue;
            }

            foreach ($filter['values'] as $option) {
                $items[] = [
                    'value' => (int) $option['id'],
                    'label' => (string) $option['name'],
                    'count' => (string) $option['count']
                ];
            }
            $filtersToStorage[lcfirst($filter['name'])] = $items;

        }

        return $filtersToStorage;
    }

    public function buildMultisearchFilters(): array
    {
        $filters = $this->getFilters();
        $requestParams = $this->request->getParams();

        $filtersFinal = [];

        foreach ($filters as $filter) {
            $requestVar = $filter->getRequestVar();
            if (!isset($requestParams[$requestVar])) {
                continue;
            }

            $value = $requestParams[$requestVar];
            $attribute = $filter->getAttributeModel();
            $attributeId = $attribute && $attribute->getAttributeId()
                ? (string) $attribute->getAttributeId()
                : null;

            if ($requestVar === 'price' && strpos($value, '-') !== false) {
                [$from, $to] = explode('-', $value);
                $filtersFinal['price'] = ['from' => $from, 'to' => $to];
            } elseif ($requestVar === 'age') {
                $adminOptions = $attribute->setStoreId(0)->getSource()->getAllOptions();
                $filtersFinal[$attributeId] = explode(',', (string) $value);
                foreach ($filtersFinal[$attributeId] as &$filterFinal) {
                    foreach ($adminOptions as $adminOption) {
                        if ($adminOption['label'] === $filterFinal) {
                            $filterFinal = $adminOption['value'];
                            break;
                        }
                    }
                }
            } elseif ($attributeId) {
                $filtersFinal[$attributeId] = explode(',', (string) $value);
            }
        }

        return $filtersFinal;
    }

    private function getFilters(): array
    {
        if ($this->cachedSearchFilters !== null) {
            return $this->cachedSearchFilters;
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('is_filterable_in_search', 1)
            ->create();

        $attributes = $this->attributeRepository->getList(
            ProductAttributeInterface::ENTITY_TYPE_CODE,
            $searchCriteria
        )->getItems();

        $filters = [];

        foreach ($attributes as $attribute) {
            if (!$attribute->getAttributeCode() || !$attribute->getAttributeId()) {
                continue;
            }

            $filters[] = new class($attribute) {
                public function __construct(
                    private readonly ProductAttributeInterface $attribute
                ) {}

                public function getAttributeModel(): ProductAttributeInterface
                {
                    return $this->attribute;
                }

                public function getRequestVar(): string
                {
                    return $this->attribute->getAttributeCode();
                }
            };
        }

        return $this->cachedSearchFilters = $filters;
    }

    private function getQueryText(): string
    {
        return $this->request->getParam('q');
    }
}
