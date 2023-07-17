<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Elastica\Client;

use Valantic\ElasticaBridgeBundle\Logger\SentryBreadcrumbLogger;

class ElasticsearchClientFactory
{
    public static function createElasticsearchClient(
        string $host,
        int $port,
        bool $addSentryBreadcrumbs,
    ): ElasticsearchClient {
        $esClient = new ElasticsearchClient([
            'host' => $host,
            'port' => $port,
        ]);

        if ($addSentryBreadcrumbs && class_exists('\Sentry\Breadcrumb')) {
            $esClient->setLogger(new SentryBreadcrumbLogger());
        }

        return $esClient;
    }
}
