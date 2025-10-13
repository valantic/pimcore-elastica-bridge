<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Elastica\Client;

use Valantic\ElasticaBridgeBundle\Logger\SentryBreadcrumbLogger;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;

class ElasticsearchClientFactory
{
    public function __construct(
        private readonly ConfigurationRepository $configurationRepository,
    ) {
    }

    public function __invoke(
    ): ElasticsearchClient {
        $logger = null;

        if ($this->configurationRepository->shouldAddSentryBreadcrumbs() && class_exists('\Sentry\Breadcrumb')) {
            $logger = (new SentryBreadcrumbLogger());
        }

        return new ElasticsearchClient(
            $this->configurationRepository->getClientDsn(),
            logger: $logger,
        );
    }
}
