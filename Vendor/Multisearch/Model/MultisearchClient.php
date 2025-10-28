<?php
declare(strict_types=1);

namespace Vendor\Multisearch\Model;

use Vendor\Multisearch\Api\ClientInterface;
use Vendor\Multisearch\Helper\DataClient;
use GuzzleHttp\Client;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ResponseFactory;
use Magento\Framework\Webapi\Rest\Request;
use Psr\Log\LoggerInterface;

class MultisearchClient implements ClientInterface
{
    public const API_REQUEST_URI = 'https://api.multisearch.io';
    public const API_REQUEST_ENDPOINT = '';

    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly ResponseFactory $responseFactory,
        private readonly DataClient $dataClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function search(
        string $query,
        array $params = [],
        string $requestMethod = Request::HTTP_METHOD_GET,
        string $apiRequestEndpoint = self::API_REQUEST_ENDPOINT
    ): array
    {
        $params = array_merge(
            $this->dataClient->getParams(),
            $params,
            ['query' => $query]
        );

        $response = $this->doRequest($apiRequestEndpoint, ['query' => $params], $requestMethod);

        if ($apiRequestEndpoint === 'history') {
            $status = $response->getStatusCode();
            return ['status' => $status];
        }

        $status = $response->getStatusCode();
        $responseBody = $response->getBody();
        $responseContent = $responseBody->getContents();

        return json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);
    }

    private function doRequest(
        string $uriEndpoint,
        array  $params = [],
        string $requestMethod = Request::HTTP_METHOD_GET
    ): Response
    {
        /** @var Client $client */
        $client = $this->clientFactory->create(['config' => [
            'base_uri' => self::API_REQUEST_URI
        ]]);

        try {
            $response = $client->request(
                $requestMethod,
                $uriEndpoint,
                $params
            );
        } catch (GuzzleException $exception) {
            $this->logger->error(
                sprintf('Multisearch request failed: %s', $exception->getMessage()),
                ['exception' => $exception]
            );
            /** @var Response $response */
            $response = $this->responseFactory->create([
                'status' => $exception->getCode(),
                'reason' => $exception->getMessage()
            ]);
        }

        return $response;
    }
}
