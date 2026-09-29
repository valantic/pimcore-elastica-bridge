<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastica\Index;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Util\ElasticsearchResponse;

class IndexSetupService
{
    public function __construct(
        private readonly ElasticsearchClient $esClient,
        private readonly PopulateLogger $logger,
    ) {
    }

    /**
     * Makes sure the index (or the blue/green indices) exist and, for blue/green, starts from an empty inactive index.
     *
     * @param bool $deleteExisting delete existing indices before creating them
     */
    public function setupIndex(IndexInterface $indexConfig, bool $deleteExisting = false): void
    {
        $this->ensureCorrectIndexSetup($indexConfig, $deleteExisting);

        if ($indexConfig->usesBlueGreenIndices()) {
            $inactiveElasticaIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();
            $inactiveElasticaIndex->delete();
            $inactiveElasticaIndex->create($indexConfig->getCreateArguments());
            $this->logger->log($indexConfig->getName(), '<comment>Re-created inactive blue/green index</comment>');
        }
    }

    /**
     * @throws ClientResponseException
     * @throws ServerResponseException
     * @throws MissingParameterException
     */
    public function switchBlueGreenIndex(IndexInterface $indexConfig): void
    {
        $this->ensureCorrectIndexSetup($indexConfig, false);

        if (!$indexConfig->usesBlueGreenIndices()) {
            return;
        }

        $this->logger->log($indexConfig->getName(), '<comment>Switching blue/green index</comment>');
        $oldIndex = $indexConfig->getBlueGreenActiveElasticaIndex();
        $newIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();
        $newIndex->flush();

        $oldIndex->removeAlias($indexConfig->getName());
        $this->logger->log($indexConfig->getName(), 'removed alias from ' . $oldIndex->getName(), ConsoleOutputInterface::VERBOSITY_VERBOSE);
        $newIndex->addAlias($indexConfig->getName());
        $this->logger->log($indexConfig->getName(), 'added alias to ' . $newIndex->getName(), ConsoleOutputInterface::VERBOSITY_VERBOSE);
        $oldIndex->flush();
        $this->postPopulateIndex($indexConfig);
    }

    private function postPopulateIndex(IndexInterface $indexConfig): void
    {
        $currentIndex = $this->esClient->getIndex($indexConfig->getName());

        if ($indexConfig->usesBlueGreenIndices()) {
            $currentIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();
        }

        $currentIndex->refresh();
    }

    private function ensureCorrectIndexSetup(IndexInterface $indexConfig, bool $deleteExisting): void
    {
        if ($indexConfig->usesBlueGreenIndices()) {
            $this->ensureCorrectBlueGreenIndexSetup($indexConfig, $deleteExisting);

            return;
        }

        $this->ensureIndexExists($indexConfig, $indexConfig->getElasticaIndex(), 'index', $deleteExisting);
    }

    private function ensureCorrectBlueGreenIndexSetup(IndexInterface $indexConfig, bool $deleteExisting): void
    {
        $nonAliasIndex = $this->esClient->getIndex($indexConfig->getName());

        // In case an index with the same name as the blue/green alias exists, delete it
        if (
            $nonAliasIndex->exists()
            && !ElasticsearchResponse::getResponse($this->esClient->indices()->existsAlias(['name' => $indexConfig->getName()]))->asBool()
        ) {
            $nonAliasIndex->delete();
            $this->logger->log($indexConfig->getName(), '<comment>Deleted non-blue/green index to prepare for blue/green usage</comment>');
        }

        foreach (IndexBlueGreenSuffix::cases() as $suffix) {
            $name = $indexConfig->getName() . $suffix->value;

            $this->ensureIndexExists($indexConfig, $this->esClient->getIndex($name), sprintf('blue/green index with alias %s', $name), $deleteExisting);
        }

        try {
            $indexConfig->getBlueGreenActiveSuffix();
        } catch (BlueGreenIndicesIncorrectlySetupException) {
            $this->esClient->getIndex($indexConfig->getName() . IndexBlueGreenSuffix::BLUE->value)
                ->addAlias($indexConfig->getName())
            ;
        }

        $this->logger->log($indexConfig->getName(), '<comment>Ensured indices are correctly set up with alias</comment>');
    }

    /**
     * Creates the index if it is missing, deleting it first if requested.
     */
    private function ensureIndexExists(IndexInterface $indexConfig, Index $index, string $description, bool $deleteExisting): void
    {
        if ($deleteExisting && $index->exists()) {
            $index->delete();
            $this->logger->log($indexConfig->getName(), sprintf('<comment>Deleted %s</comment>', $description));
        }

        if (!$index->exists()) {
            $index->create($indexConfig->getCreateArguments());
            $this->logger->log($indexConfig->getName(), sprintf('<comment>Created %s</comment>', $description));
        }
    }
}
