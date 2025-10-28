<?php
namespace Vendor\Multisearch\Api;

use Vendor\Xsearch\Api\SearchInterface;
use Vendor\Xsearch\Api\Data\SearchResultInterface;

interface MultisearchInterface extends SearchInterface
{
    /**
     * @param string $query
     * @param int $storeId
     * @param string $currencyCode
     * @param int|null $page
     * @param int|null $pageSize
     * @return SearchResultInterface
     */
    public function search(
        string $query,
        int $storeId,
        string $currencyCode,
        ?int $page = null,
        ?int $pageSize = null
    ): SearchResultInterface;
}
