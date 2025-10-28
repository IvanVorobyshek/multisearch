<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Plugin;

use Vendor\Multisearch\Helper\Data;
use Vendor\Multisearch\Model\Search\FiltersStorage;
use Magento\Catalog\Model\Layer\Filter\ItemFactory;
use Magento\LayeredNavigation\Block\Navigation;

class LayeredNavigationFilters
{
    public function __construct(
        private readonly FiltersStorage $filtersStorage,
        private readonly ItemFactory $filterItemFactory,
        private readonly Data $multisearchData,
    ) {
    }

    public function afterGetFilters(Navigation $subject, $result): array
    {
        if ($this->multisearchData->shouldApplyManualLimit()) {
            return $result;
        }

        if ($subject->getData('multisearch_filters_added') === true) {
            return $result;
        }

        $customOptions = $this->filtersStorage->get();

        foreach ($result as $filter) {
            $filterCode = $filter->getRequestVar();
            if (isset($customOptions[$filterCode])) {
                $items = $filter->getItems();

                foreach ($customOptions[$filterCode] as $option) {
                    $item = $this->filterItemFactory->create();
                    $item->setFilter($filter)
                        ->setLabel($option['label'])
                        ->setValue($option['value'])
                        ->setCount($option['count']);

                    if ($filterCode === 'age') {
                        $item->setAdminLabel($option['admin_label'])
                            ->setStoreLabel($option['store_label']);
                    }

                    if ($filterCode === 'color_hex') {
                        $item->setColor($option['color']);
                    }

                    if ($filterCode === 'price') {
                        $item->setPriceFrom($option['price_from'])
                            ->setPriceTo($option['price_to'])
                            ->setPriceMin($option['price_min'])
                            ->setPriceMax($option['price_max']);
                    }

                    $items[] = $item;
                }

                $filter->setItems($items);
            }
        }

        if ($customOptions !== []) {
            $subject->setData('multisearch_filters_added', true);
        }

        return $result;
    }
}
