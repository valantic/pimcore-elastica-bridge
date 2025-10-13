<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Elastica\Client;

use Elastica\Client;

/**
 * When typehinted, this class provides an Elastica client pre-configured with port and host.
 *
 * @see ElasticsearchClientFactory
 */
class ElasticsearchClient extends Client
{
}
