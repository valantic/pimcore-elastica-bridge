<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Elastica\Client;

use Valantic\ElasticaBridgeBundle\Logger\SentryBreadcrumbLogger;

class ElasticsearchClientFactory
{
    public static function createElasticsearchClient(
        string $host,
        int $port,
        ?string $dsn,
        bool $addSentryBreadcrumbs,
    ): ElasticsearchClient {
        $config = $dsn !== null && $dsn !== ''
            ? $dsn
            : [
                'host' => $host,
                'port' => $port,
            ];

        $esClient = new ElasticsearchClient($config);

        if ($addSentryBreadcrumbs && class_exists('\Sentry\Breadcrumb')) {
            $esClient->setLogger(new SentryBreadcrumbLogger());
        }

        return $esClient;
    }
}
