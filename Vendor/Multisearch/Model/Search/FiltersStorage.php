<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Model\Search;

class FiltersStorage
{
    private array $filters = [];

    public function set(array $filters): void
    {
        $this->filters = $filters;
    }

    public function get(): array
    {
        return $this->filters;
    }
}
