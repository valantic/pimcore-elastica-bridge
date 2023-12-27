<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Elastica\Client;

use Elastic\Elasticsearch\Response\Elasticsearch;
use Elastica\Client;

/**
 * When typehinted, this class provides an Elastica client pre-configured with port and host.
 *
 * @see ElasticsearchClientFactory
 */
class ElasticsearchClient extends Client
{
    /**
     * @param array<string,string> $headers
     */
    public function request(string $method, string $url, array $headers = [], mixed $body = null): Elasticsearch
    {
        $result = $this->sendRequest($this->createRequest($method, $url, $headers, $body));

        if (!$result instanceof Elasticsearch) {
            throw new \RuntimeException();
        }

        return $result;
    }
}
