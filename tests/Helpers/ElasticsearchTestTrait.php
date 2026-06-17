<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Helpers;

use Elastica\Client;
use Elastica\Index;

trait ElasticsearchTestTrait
{
    protected ?Client $elasticaClient = null;
    protected array $testIndices = [];

    protected function getElasticsearchHost(): string
    {
        return $_ENV['ELASTICSEARCH_HOST'] ?? 'localhost:9200';
    }

    protected function createElasticsearchClient(): Client
    {
        if ($this->elasticaClient === null) {
            $this->elasticaClient = new Client([
                'host' => explode(':', $this->getElasticsearchHost())[0],
                'port' => (int) (explode(':', $this->getElasticsearchHost())[1] ?? 9200),
            ]);
        }

        return $this->elasticaClient;
    }

    protected function createTestIndex(string $name, array $config = []): Index
    {
        $client = $this->createElasticsearchClient();
        $index = $client->getIndex($name);

        if ($index->exists()) {
            $index->delete();
        }

        $index->create($config);
        $this->testIndices[] = $name;

        return $index;
    }

    protected function cleanupTestIndices(): void
    {
        if ($this->elasticaClient === null) {
            return;
        }

        foreach ($this->testIndices as $indexName) {
            $index = $this->elasticaClient->getIndex($indexName);

            if ($index->exists()) {
                $index->delete();
            }
        }

        $this->testIndices = [];
    }

    protected function tearDownElasticsearch(): void
    {
        $this->cleanupTestIndices();
        $this->elasticaClient = null;
    }
}
