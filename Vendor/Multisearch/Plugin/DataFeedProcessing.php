<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Plugin;

use Amasty\Feed\Model\Export\Adapter\Xml;
use Vendor\Multisearch\Helper\Data;
use Exception;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Model\ProductRepository;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableType;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class DataFeedProcessing
{
    private const AGE_TO_NUM = 99999;
    private const PARENT_CHILD_FILTER = ['clothing_size', 'shoe_size'];

    private ?array $filterableAttributeCodes = null;
    private ?array $ageRanges = null;
    private ?array $attributeMeta = null;
    private ?int $storeId = null;

    public function __construct(
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ConfigurableType $configurableType,
        private readonly ProductRepository $productRepository,
    ) {
    }

    public function beforeWriteDataRow(Xml $subject, array &$rowData): array
    {
        $storeId = $this->getStoreId($subject);

        $this->processCategoryIds($rowData);
        $this->checkPrice($rowData);
        $this->replaceUrlWithChildProductUrl($rowData);
        $this->wrapParamAttributes($rowData, $storeId);

        return [$rowData];
    }

    private function getStoreId(Xml $subject): ?int
    {
        if ($this->storeId === null) {
            $content = $subject->getContents();

            if (preg_match('/<store_id>(\d+)<\/store_id>/', $content, $matches)) {
                $storeId = (int) $matches[1];
            }

            $this->storeId = $storeId;
        }

        return $this->storeId;
    }

    private function checkPrice(array &$rowData): void
    {
        if (!isset($rowData['price|final_price'], $rowData['price|price'])) {
            return;
        }

        if ($rowData['price|final_price'] === $rowData['price|price']) {
            unset($rowData['price|price']);
        } else {
            $rowData['price|price'] = "<oldprice>{$rowData['price|price']}</oldprice>";
        }
    }

    private function replaceUrlWithChildProductUrl(array &$rowData): void
    {
        if (($rowData['basic|product_type'] ?? '') !== 'configurable') {
            unset($rowData['basic|product_type']);
            return;
        }

        unset($rowData['basic|product_type']);
        $parentId = (int)($rowData['basic|product_id'] ?? 0);
        if (!$parentId) {
            return;
        }

        $childIds = $this->configurableType->getChildrenIds($parentId)[0] ?? [];
        foreach ($childIds as $childId) {
            try {
                $child = $this->productRepository->getById($childId, false, $this->storeId);

                if (!$child->isAvailable()) {
                    continue;
                }

                $rowData['url|short'] = $child->getProductUrl();
                return;
            } catch (Exception $e) {
                continue;
            }
        }
    }

    private function processCategoryIds(array &$rowData): void
    {
        if (empty($rowData['advanced|category_ids'])) {
            return;
        }

        $categoryIds = explode(',', $rowData['advanced|category_ids']);
        $tags = [];

        foreach ($categoryIds as $id) {
            $id = trim($id);
            if ($id !== '') {
                $tags[] = "<categoryId>{$id}</categoryId>";
            }
        }

        $rowData['advanced|category_ids_multiple'] = implode("\n", $tags);
    }

    private function wrapParamAttributes(array &$rowData, int $storeId): void
    {
        $meta = $this->getAttributeMeta($storeId);

        if (isset($rowData['image|image'])) {
            $rowData['image|image'] = str_replace('http://', 'https://', $rowData['image|image']);
        }

        if (isset($rowData['url|short'])) {
            $rowData['url|short'] = str_replace('http://', 'https://', $rowData['url|short']);
        }

        foreach ($this->getFilterableAttributeCodes() as $code) {
            $key = "product|$code";

            if ($code === Data::AGE_FILTER_CODE) {
                $ranges = $this->ageRanges();

                $ageFrom = isset($rowData['product|age_from']) ? (int)$rowData['product|age_from'] : null;
                $ageTo = isset($rowData['product|age_to']) ? (int)$rowData['product|age_to'] : null;

                if ($ageFrom === null && $ageTo === null) {
                    continue;
                }

                if (!$ageFrom) {
                    $ageFrom = 0;
                }

                if (!$ageTo) {
                    $ageTo = self::AGE_TO_NUM;
                }

                $ageParams = [];
                $attributeData = $meta[$code] ?? [];
                $attributeId = $attributeData['id'] ?? '';

                foreach ($ranges as $range) {
                    if ($range['to'] >= $ageFrom && $range['from'] <= $ageTo) {
                        $escapedValue = htmlspecialchars($range['label']);
                        $valueId = $attributeData['options'][$escapedValue]['value'] ?? '';
                        $label = $attributeData['options'][$escapedValue]['label'] ?? '';
                        $ageParams[] = sprintf(
                            '<param id="%s" name="%s" valueId="%s" label="%s" filter="true">%s</param>',
                            $attributeId,
                            $code,
                            $valueId,
                            $label,
                            $escapedValue
                        );
                    }
                }

                if ($ageParams) {
                    $rowData['product|age_from'] = implode("\n", $ageParams);
                    unset($rowData['product|age_to']);
                } else {
                    unset($rowData['product|age_from'], $rowData['product|age_to']);
                }

                continue;
            }

            if (isset($rowData[$key]) && trim((string)$rowData[$key]) !== '') {
                $value = trim((string)$rowData[$key]);

                $attributeData = $meta[$code] ?? [];
                $attributeId = $attributeData['id'] ?? '';
                $name = htmlspecialchars($code);

                $parts = array_map('trim', explode('|', $value));
                $params = [];

                foreach ($parts as $part) {
                    if ($part === '') {
                        continue;
                    }

                    $escapedValue = htmlspecialchars($part);
                    $valueId = $attributeData['options'][$part] ?? '';

                    if ($name === 'brand') {
                        $tag = 'brand';
                    } else {
                        $tag = 'param';
                    }

                    if ($name === 'color_hex') {
                        $options = $attributeData['options'];
                        foreach ($options as $optionKey => $option) {
                            if ($part === $option['label']) {
                                $valueId = $option['value'];
                                $label = $optionKey;
                                break;
                            }
                        }
                        $params[] = sprintf(
                            '<%s id="%s" valueId="%s" filter="true" label="%s" name="%s">%s</%s>',
                            $tag,
                            $attributeId,
                            $valueId,
                            $label,
                            $name,
                            $escapedValue,
                            $tag
                        );
                    } else {
                        if (in_array($name, self::PARENT_CHILD_FILTER, true)) {
                            $params[] = sprintf(
                                '<param id="%s" valueId="%s" filter="true" name="%s" group="true">%s</param>',
                                $attributeId,
                                $valueId,
                                $name,
                                $escapedValue
                            );
                        } else {
                            $params[] = sprintf(
                                '<%s id="%s" valueId="%s" filter="true" name="%s">%s</%s>',
                                $tag,
                                $attributeId,
                                $valueId,
                                $name,
                                $escapedValue,
                                $tag
                            );
                        }
                    }
                }

                if (!empty($params)) {
                    $rowData[$key] = implode("\n", $params);
                }
            }
        }
    }

    private function ageRanges(): array
    {
        if ($this->ageRanges !== null) {
            return $this->ageRanges;
        }

        $attribute = $this->attributeRepository->get(
            ProductAttributeInterface::ENTITY_TYPE_CODE,
            Data::AGE_FILTER_CODE
        );

        $options = $attribute->setStoreId(0)->getSource()->getAllOptions();
        $ranges = [];

        foreach ($options as $option) {
            $label = trim((string)($option['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $parts = explode('-', $label);
            $from = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : null;
            $to   = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;

            if ($from !== null && $to !== null) {
                $ranges[] = ['label' => $label, 'from' => $from, 'to' => $to];
            } elseif ($from !== null) {
                $ranges[] = ['label' => $label, 'from' => $from, 'to' => self::AGE_TO_NUM];
            }
        }

        $this->ageRanges = $ranges;
        return $this->ageRanges;
    }

    private function getFilterableAttributeCodes(): array
    {
        if ($this->filterableAttributeCodes !== null) {
            return $this->filterableAttributeCodes;
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('is_filterable_in_search', 1)
            ->create();

        $attributes = $this->attributeRepository->getList(
            ProductAttributeInterface::ENTITY_TYPE_CODE,
            $searchCriteria
        )->getItems();

        $this->filterableAttributeCodes = array_values(array_map(
            fn($attr) => $attr->getAttributeCode(),
            array_filter($attributes, fn($attr) => !in_array(
                $attr->getAttributeCode(),
                Data::EXCLUDED_ATTRIBUTES,
                true
            ))
        ));

        return $this->filterableAttributeCodes;
    }

    private function getAttributeMeta(int $storeId): array
    {
        if ($this->attributeMeta !== null) {
            return $this->attributeMeta;
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('is_filterable_in_search', 1)
            ->create();

        $attributes = $this->attributeRepository->getList(
            ProductAttributeInterface::ENTITY_TYPE_CODE,
            $searchCriteria
        )->getItems();

        $result = [];

        foreach ($attributes as $attr) {
            $code = $attr->getAttributeCode();

            if (in_array($code, Data::EXCLUDED_ATTRIBUTES, true)) {
                continue;
            }

            $result[$code] = [
                'id' => (int)$attr->getAttributeId(),
                'frontend_label' => $attr->getDefaultFrontendLabel(),
                'options' => [],
            ];

            if ($code === Data::AGE_FILTER_CODE || $code === Data::COLOR_HEX_FILTER_CODE) {
                $ageOptions = $attr->setStoreId(0)->getSource()->getAllOptions();
            }
            $options = $attr->setStoreId($storeId)->getOptions();

            foreach ($options as $option) {
                if (!isset($option['value']) || !isset($option['label'])) {
                    continue;
                }

                $label = trim((string)$option['label']);
                if ($label !== '') {
                    if ($code === Data::AGE_FILTER_CODE || $code === Data::COLOR_HEX_FILTER_CODE) {
                        $ageData['value'] = (int)$option['value'];
                        $ageData['label'] = $option['label'];
                        foreach ($ageOptions as $ageOption) {
                            if ((int)$option['value'] === (int)$ageOption['value']) {
                                $result[$code]['options'][$ageOption['label']] = $ageData;
                                break;
                            }
                        }
                    } else {
                        $result[$code]['options'][$label] = (int)$option['value'];
                    }
                }
            }
        }

        $this->attributeMeta = $result;
        
        return $this->attributeMeta;
    }
}
