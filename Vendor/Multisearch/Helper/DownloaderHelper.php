<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class DownloaderHelper
{
    public const FEED_STORAGE_FOLDER = 'amasty_feed/general/storage_folder';
    public const FEED_STORAGE_FILEPATH = 'amasty_feed/general/file_path';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getFeedStorageFolder(): string
    {
        return (string)$this->scopeConfig->getValue(self::FEED_STORAGE_FOLDER,
            ScopeInterface::SCOPE_WEBSITE
        );
    }

    public function getFeedStorageFilepath(): string
    {
        return (string)$this->scopeConfig->getValue(self::FEED_STORAGE_FILEPATH,
            ScopeInterface::SCOPE_WEBSITE
        );
    }

    public function getFeedStorageFullPath(): string
    {
        return '/' . $this->getFeedStorageFolder() . '/' . $this->getFeedStorageFilepath() . '/';
    }
}
