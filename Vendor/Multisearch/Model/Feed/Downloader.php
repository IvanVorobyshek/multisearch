<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Model\Feed;

use Amasty\Feed\Api\Data\FeedInterface;
use Amasty\Feed\Model\Feed\Downloader as AmastyDownloader;
use Amasty\Feed\Model\Filesystem\FeedOutput;
use Vendor\Multisearch\Helper\DownloaderHelper;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Filesystem\DirectoryList;

class Downloader extends AmastyDownloader
{
    public function __construct(
        private RawFactory             $rawResultFactory,
        private FeedOutput             $feedOutput,
        private readonly DirectoryList $directoryList,
        private readonly DownloaderHelper $downloaderHelper,
    )
    {
        parent::__construct($rawResultFactory, $feedOutput);
    }

    public function getResponse(FeedInterface $feed)
    {
        $filePath = $this->directoryList->getRoot() . $this->downloaderHelper->getFeedStorageFullPath() . $feed->getFileName();

        if (!file_exists($filePath)) {
            header("HTTP/1.1 404 Not Found");
            exit('File not found');
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        while (ob_get_level()) {
            ob_end_clean();
        }

        readfile($filePath);
        exit;
    }
}
