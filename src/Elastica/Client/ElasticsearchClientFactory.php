<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Elastica\Client;

use Valantic\ElasticaBridgeBundle\Logger\SentryBreadcrumbLogger;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;

class ElasticsearchClientFactory
{
    public function __construct(
        private readonly ConfigurationRepository $configurationRepository,
    ) {}

    public function __invoke(
    ): ElasticsearchClient {
        $esClient = new ElasticsearchClient($this->configurationRepository->getClient());

        if ($this->configurationRepository->getAddSentryBreadcrumbs() && class_exists('\Sentry\Breadcrumb')) {
            $esClient->setLogger(new SentryBreadcrumbLogger());
        }

        return $esClient;
    }
}
