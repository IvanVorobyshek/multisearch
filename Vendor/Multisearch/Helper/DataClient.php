<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class DataClient
{
    public const MULTISEARCH_STORE_ID = 'xsearch_config/multisearch/multisearch_store_id';
    public const PAGE_SIZE_PATH = 'catalog/frontend/grid_per_page';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly CookieManagerInterface $cookieManager,
    ) {
    }

    public function getMultisearchStoreId(): string
    {
        return (string)$this->scopeConfig->getValue(
            self::MULTISEARCH_STORE_ID,
            ScopeInterface::SCOPE_WEBSITE
        );
    }

    public function getParams(): array
    {
        $params = [
            'id' => $this->getMultisearchStoreId(),
            'lang' => $this->storeManager->getStore()->getCode(),
        ];

        return $params;
    }

    public function getProductsPageSize(): int
    {
        return (int)$this->scopeConfig->getValue(
            self::PAGE_SIZE_PATH,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getPageSize(): int
    {
        $pageSize = $this->getProductsPageSize();
        $page = $this->getCurrentPage();
        $pages = $this->getPagesNum();

        if ($pages > $page) {
            $pageSize *= $pages;
        }

        return $pageSize;
    }

    public function getOffset(): int
    {
        $limit = $this->getProductsPageSize();
        $page = $this->getCurrentPage();

        return $limit * ($page - 1);
    }

    public function getCurrentPage(): int
    {
        return $this->request->getParam('p') ? (int)$this->request->getParam('p') : 1;
    }

    public function getPagesNum(): int
    {
        return $this->request->getParam('pages') ? (int)$this->request->getParam('pages') : 1;
    }

    public function getStoreCode(): string
    {
        return $this->storeManager->getStore()->getCode();
    }

    public function getCustomerIdFromCookie(): int
    {
        return (int)$this->cookieManager->getCookie('m2_user_id');
    }
}
