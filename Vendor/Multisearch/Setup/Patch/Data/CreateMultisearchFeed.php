<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Setup\Patch\Data;

use Amasty\Feed\Api\FeedRepositoryInterface;
use Amasty\Feed\Model\FeedFactory;
use Amasty\Feed\Model\Rule\Condition\Combine;
use Amasty\Feed\Model\Schedule\Management;
use Vendor\Multisearch\Helper\Data;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class CreateMultisearchFeed implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly FeedFactory $feedFactory,
        private readonly FeedRepositoryInterface $feedRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Management $scheduleManagement,
        private readonly State $appState,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
    ) {
    }

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (LocalizedException) {
        }

        $ukCronTime = '1380';
        $ruCornTime = '1410';

        foreach ($this->storeManager->getStores() as $store) {
            $available = $this->getAvailableText($store->getCode(), true);
            $unAvailable = $this->getAvailableText($store->getCode(), false);
            $baseUrl = rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true), '/');
            $paramBlock = $this->getParamAttributesContent();

            $feed = $this->feedFactory->create();

            $feed->setName('Multisearch_' . $store->getCode());
            $feed->setFileName('multisearch_' . $store->getCode());
            $feed->setFeedType('xml');
            $feed->setStoreId($store->getId());
            $feed->setIsActive(true);
            $feed->setParentPriority("configurable");
            $feed->setExcludeDisabled(true);
            $feed->setExcludeSubdisabled(true);
            $feed->setExcludeOutOfStock(false);
            $feed->setExcludeNotVisible(false);
            $feed->setXmlItem('');
            $feed->setFormatPriceCurrency("UAH");
            $feed->setFormatPriceCurrencyShow('0');
            $feed->setFormatPriceDecimalPoint('dot');
            $feed->setFormatPriceThousandsSeparator('empty');
            $feed->setExecuteMode('schedule');
            $feed->setCronDay([
                0 => '0',
                1 => '1',
                2 => '2',
                3 => '3',
                4 => '4',
                5 => '5',
                6 => '6'
            ]);
            if ($store->getCode() === 'uk') {
                $feed->setCronTime([0 => $ukCronTime]);
            } else {
                $feed->setCronTime([0 => $ruCornTime]);
            }

            $feed->setDeliveryEnabled(0);

            $feed->setXmlHeader(<<<XML
<?xml version="1.0"?>
<yml_catalog>
<shop>
<name>Vendor M2</name>
<url>{$baseUrl}</url>
<store_id>{$store->getId()}</store_id>
<currencies>
<currency id="UAH" rate="1"/>
</currencies>
<presences>
<presence>{$available}</presence>
<presence>{$unAvailable}</presence>
</presences>
<categories></categories>
<offers>
XML);

            $feed->setXmlFooter('</offers></shop></yml_catalog>');

            $feed->setXmlContent(<<<XML
<offer id="{attribute="basic|product_id" format="as_is" parent="no" optional="no" modify=""}" available="{attribute="inventory|is_in_stock" format="as_is" parent="no" optional="yes" modify=""}" group_id="{attribute="basic|product_id" format="as_is" parent="yes"}">
<name>{attribute="product|name" modify="wrap_cdata"}</name>
<vendorCode>{attribute="basic|sku"}</vendorCode>
<code>{attribute="basic|sku"}</code>
<categoryIdAll>{attribute="advanced|category_ids"}</categoryIdAll>
{attribute="advanced|category_ids_multiple"}
<url>{attribute="url|short"}</url>
<presence>{attribute="inventory|is_in_stock" modify="replace:1^{$available}|replace:0^{$unAvailable}"}</presence>
<price>{attribute="price|final_price" format="price"}</price>
{attribute="price|price" format="price"}
<price_min>{attribute="price|min_price" format="price"}</price_min>
<picture>{attribute="image|image"}</picture>
<description>{attribute="product|description" modify="wrap_cdata"}</description>
{attribute="basic|product_type" format="as_is" parent="no" optional="yes" modify=""}
{$paramBlock}
</offer>

XML);
            $feed->setConditionsSerialized(json_encode([
                'type' => Combine::class,
                'attribute' => null,
                'operator' => null,
                'value' => '1',
                'is_value_processed' => null,
                'aggregator' => 'all'
            ]));

            $this->feedRepository->save($feed, true);

            if ($feed->getId()) {
                $this->scheduleManagement->saveScheduleData($feed->getId(), $feed->getData());
            }
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    private function getParamAttributesContent(): string
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('is_filterable_in_search', 1)
            ->create();

        $attributes = $this->attributeRepository->getList(
            ProductAttributeInterface::ENTITY_TYPE_CODE,
            $searchCriteria
        )->getItems();

        $lines = [];

        foreach ($attributes as $attribute) {
            $code = $attribute->getAttributeCode();

            if (in_array($code, Data::EXCLUDED_ATTRIBUTES, true)) {
                continue;
            }

            if ($code === Data::AGE_FILTER_CODE) {
                $lines[] = sprintf('{attribute="product|age_from" optional="yes"}');
                $lines[] = sprintf('{attribute="product|age_to" optional="yes"}');
            } else {
                $lines[] = sprintf('{attribute="product|%s" optional="yes"}', $code);
            }
        }

        return implode("\n", $lines);
    }

    private function getAvailableText(string $storeCode, bool $available = false): string
    {
        $phrases = [
            'ru' => ['yes' => 'Есть в наличии', 'no' => 'Нет в наличии'],
            'default' => ['yes' => 'Є в наявності', 'no' => 'Немає в наявності'],
        ];

        $lang = $phrases[$storeCode] ?? $phrases['default'];
        return $available ? $lang['yes'] : $lang['no'];
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
