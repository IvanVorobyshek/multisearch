<?php declare(strict_types=1);

namespace Vendor\Multisearch\Observer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\StoreManagerInterface;

class SearchConfigurableProduct implements ObserverInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductResource $productResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {

    }
    public function execute(Observer $observer): void
    {
        $productData = $observer->getData('product');
        $responseItemData = $observer->getData('responseItemData');
        $query = (string)$observer->getData('query');
        $productId = $responseItemData['id'] ?? null;
        if (!$productId || !$query) return;

        try {
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException $e) {
            $this->logger->info('multisearch: '.$e->getMessage());
            return;
        }

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $childrenIds = $product->getExtensionAttributes()->getConfigurableProductLinks();
            $childrenSkus = array_column($this->productResource->getProductsSku($childrenIds), 'sku');
            if (in_array($query, $childrenSkus)) {
                $childProduct = $this->productRepository->get($query, false, $this->storeManager->getStore()->getId());
                if ($childProduct) {
                    $productData->setUrl(
                        $childProduct->getProductUrl()
                    );
                }
            }
        }
    }
}
