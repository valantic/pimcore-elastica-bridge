<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class CleanupService
{
    public function __construct(
        private readonly ElasticsearchClient $esClient,
        private readonly IndexRepository $indexRepository,
    ) {}

    /**
     * @param bool $allIndices
     *
     * @throws ClientResponseException
     * @throws MissingParameterException
     * @throws ServerResponseException
     *
     * @return \Generator<string>
     */
    public function cleanUp(bool $allIndices = false, bool $dryRun = true): \Generator
    {
        if ($dryRun === true) {
            yield '<info>DRY-RUN MODE</info>';
        }

        $indices = $this->getIndices($allIndices);

        foreach ($indices as $index) {
            if ($index === '.geoip_databases') {
                continue;
            }

            $esIndex = $this->esClient->getIndex($index);

            if ($esIndex->getSettings()->getBool('hidden')) {
                continue;
            }

            foreach ($esIndex->getAliases() as $alias) {
                if ($dryRun === true) {
                    yield sprintf('<info>Would remove alias %s from index %s</info>', $alias, $index);

                    continue;
                }
                $esIndex->removeAlias($alias);

                yield sprintf('<info>Removed alias %s from index %s</info>', $alias, $index);
            }

            try {
                if ($dryRun === true) {
                    yield sprintf('<info>Would delete index %s</info>', $index);

                    continue;
                }
                $esIndex->delete();

                yield sprintf('<info>Deleted index %s</info>', $index);
            } catch (ElasticsearchException $e) {
                yield sprintf('<error>%s</error>', $e->getMessage());
            }
        }
    }

    /**
     * @param bool $allIndices
     *
     * @return string[]
     */
    private function getIndices(bool $allIndices = false): array
    {
        if ($allIndices === true) {
            return $this->esClient->getCluster()->getIndexNames();
        }

        $indices = [];

        foreach ($this->indexRepository->flattenedAll() as $indexConfig) {
            if ($indexConfig->usesBlueGreenIndices()) {
                $indices[] = $indexConfig->getBlueGreenActiveElasticaIndex()->getName();
                $indices[] = $indexConfig->getBlueGreenInactiveElasticaIndex()->getName();

                continue;
            }

            $indices[] = $indexConfig->getName();
        }

        return $indices;
    }
}
