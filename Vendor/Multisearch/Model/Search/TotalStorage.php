<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Model\Search;

class TotalStorage
{
    private ?int $total = null;

    public function set(int $total): void
    {
        $this->total = $total;
    }

    public function get(): ?int
    {
        return $this->total;
    }
}
