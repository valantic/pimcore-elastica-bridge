<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastica\Exception\ExceptionInterface as ElasticaException;
use Valantic\ElasticaBridgeBundle\Command\NonBundleIndexTrait;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Model\CleanupResult;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class CleanupService
{
    use NonBundleIndexTrait;

    public function __construct(
        private readonly ElasticsearchClient $esClient,
        private readonly IndexRepository $indexRepository,
    ) {
    }

    /**
     * Deletes Elasticsearch indices and their aliases.
     *
     * @param bool $allInCluster also delete indices not created by this bundle
     * @param bool $dryRun only report what would be deleted
     */
    public function cleanUp(bool $allInCluster = false, bool $dryRun = false): CleanupResult
    {
        $removedAliases = [];
        $deletedIndices = [];
        $errors = [];

        foreach ($this->getIndexNames($allInCluster) as $indexName) {
            if (!$this->shouldProcessNonBundleIndex($indexName)) {
                continue;
            }

            $index = $this->esClient->getIndex($indexName);

            try {
                if ($index->getSettings()->getBool('hidden')) {
                    continue;
                }

                foreach ($index->getAliases() as $alias) {
                    if (!$dryRun) {
                        $index->removeAlias($alias);
                    }

                    $removedAliases[$indexName][] = $alias;
                }

                if (!$dryRun) {
                    $index->delete();
                }

                $deletedIndices[] = $indexName;
            } catch (ElasticsearchException|ElasticaException $e) {
                $errors[$indexName] = $e->getMessage();
            }
        }

        return new CleanupResult($dryRun, $removedAliases, $deletedIndices, $errors);
    }

    /**
     * @return string[]
     */
    private function getIndexNames(bool $allInCluster): array
    {
        if ($allInCluster) {
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
