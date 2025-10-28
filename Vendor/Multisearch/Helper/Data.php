<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    public const API_ENDPOINT_HISTORY_DELETE = 'history';

    public const EXCLUDED_ATTRIBUTES = ['price'];
    public const AGE_FILTER_CODE = 'age';
    public const COLOR_HEX_FILTER_CODE = 'color_hex';

    public const AMASTY_SEARCH_ENGINE = 'amasty_elastic';
    public const MULTISEARCH_SEARCH_ENGINE = 'multisearch';
    public const CATALOG_SEARCH_ENGINE_PATH = 'catalog/search/engine';
    public const MULTISEARCH_SUGGESTS_NUMBER_PATH = 'xsearch_config/multisearch/multisearch_suggests_number';
    public const MULTISEARCH_EXTRA_CONDITIONS_PATH = 'xsearch_config/multisearch/multisearch_extra_conditions';

    public function __construct(
        Context $context,
        private readonly RequestInterface $request,
    ) {
        parent::__construct($context);
    }

    private function getCurrentSearchEngine(): ?string
    {
        return $this->scopeConfig->getValue(self::CATALOG_SEARCH_ENGINE_PATH,
            ScopeInterface::SCOPE_WEBSITE
        );
    }

    public function isMultisearchEngine(): bool
    {
        return $this->getCurrentSearchEngine() === self::MULTISEARCH_SEARCH_ENGINE;
    }

    public function shouldApplyManualLimit(): bool
    {
        return !($this->request->getFullActionName() === 'catalogsearch_result_index' && $this->isMultisearchEngine());
    }

    public function getMultisearchSuggestsNumber(): int
    {
        return (int)$this->scopeConfig->getValue(self::MULTISEARCH_SUGGESTS_NUMBER_PATH,
            ScopeInterface::SCOPE_WEBSITE
        );
    }

    public function shouldApplyExtraConditionsForFeed(): int
    {
        return (int)$this->scopeConfig->getValue(self::MULTISEARCH_EXTRA_CONDITIONS_PATH,
            ScopeInterface::SCOPE_WEBSITE
        );
    }
}
