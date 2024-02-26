<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Util;

use Elastic\Elasticsearch\Response\Elasticsearch;
use Http\Promise\Promise;

class ElasticsearchResponse
{
    /**
     * This method checks if the response object is an instance of Elasticsearch.
     * If it's not an instance of Elasticsearch, it throws a \RuntimeException.
     * If it's an instance of Elasticsearch, it returns the response directly.
     * This is mainly done for the benefit of PHPStan.
     *
     * @throws \RuntimeException if the response is not an instance of Elasticsearch
     */
    public static function getResponse(Elasticsearch|Promise $response): Elasticsearch
    {
        if (!$response instanceof Elasticsearch) {
            throw new \RuntimeException();
        }

        return $response;
    }
}
