<?php

namespace Vendor\Multisearch\Api;

interface ClientInterface
{
    /**
     * Make search request
     *
     * @param string $query
     * @param array $params
     * @return array
     */
    public function search(string $query, array $params = []): array;
}
