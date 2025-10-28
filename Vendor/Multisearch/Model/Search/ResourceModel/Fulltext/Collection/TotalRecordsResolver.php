<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Model\Search\ResourceModel\Fulltext\Collection;

use Vendor\Multisearch\Model\Search\TotalStorage;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection\TotalRecordsResolverInterface;

class TotalRecordsResolver implements TotalRecordsResolverInterface
{
    public function __construct(
        private readonly TotalStorage $totalStorage,
    ) {
    }

    public function resolve(): ?int
    {
        return $this->totalStorage->get();
    }
}
