<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Plugin;

use Vendor\Multisearch\Helper\Data as MultisearchHelper;
use Magento\Framework\App\RequestInterface;
use Magento\Search\Model\EngineResolver;

class EngineResolverPlugin
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly MultisearchHelper $helper,
    ) {
    }

    public function afterGetCurrentSearchEngine(
        EngineResolver $subject,
        $result
    ): string
    {
        if (!$this->helper->isMultisearchEngine()) {
            return $result;
        }

        $fullAction = $this->request->getFullActionName();
        $pathInfo = $this->request->getPathInfo();

        if ($fullAction === 'catalogsearch_result_index' || str_starts_with($pathInfo, '/rest/V1/multisearch')) {
            return $result;
        }

        return MultisearchHelper::AMASTY_SEARCH_ENGINE;
    }
}
