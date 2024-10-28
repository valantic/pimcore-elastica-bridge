<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;
use Valantic\ElasticaBridgeBundle\Exception\Index\AlreadyInProgressException;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocument;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Messenger\Message\SwitchIndex;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Util\ElasticsearchResponse;

class PopulateIndexService
{
    private bool $shouldDelete = false;

    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly ElasticsearchClient $esClient,
        private readonly LockService $lockService,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentHelper $documentHelper,
        private ConsoleOutputInterface $consoleOutput,
    ) {}

    public function triggerAllIndices(): \Generator
    {
        foreach ($this->indexRepository->flattenedAll() as $indexConfig) {
            try {
                $this->checkIndex($indexConfig);

                $this->setupIndex($indexConfig);

                yield from $this->generateMessagesForIndex($indexConfig);
            } catch (AlreadyInProgressException $e) {
                $this->log($indexConfig->getName(), '<fg=red>' . $e->getMessage() . '</>');

                continue;
            }
        }
    }

    public function triggerSingleIndex(
        IndexInterface $indexConfig,
        bool $populate = false,
        bool $ignoreLock = false,
        bool $ignoreCooldown = false,
    ): \Generator {
        try {
            $this->checkIndex($indexConfig, $ignoreCooldown, $ignoreLock);

            $this->setupIndex($indexConfig);

            if (!$populate) {
                return;
            }

            yield from $this->generateMessagesForIndex($indexConfig);
        } catch (AlreadyInProgressException $e) {
            $this->log($indexConfig->getName(), '<fg=red>' . $e->getMessage() . '</>');
        }
    }

    public function setShouldDelete(bool $shouldDelete): self
    {
        $this->shouldDelete = $shouldDelete;

        return $this;
    }

    public function setupIndex(IndexInterface $indexConfig): void
    {
        $this->ensureCorrectIndexSetup($indexConfig);

        if ($indexConfig->usesBlueGreenIndices()) {
            $inactiveElasticaIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();
            $inactiveElasticaIndex->delete();
            $inactiveElasticaIndex->create($indexConfig->getCreateArguments());
            $this->log($indexConfig->getName(), '<comment>Re-created inactive blue/green index</comment>');
        }
    }

    public function postPopulateIndex(IndexInterface $indexConfig): void
    {
        $currentIndex = $this->esClient->getIndex($indexConfig->getName());

        if ($indexConfig->usesBlueGreenIndices()) {
            $currentIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();
        }

        $currentIndex->refresh();
    }

    /**
     * @throws ClientResponseException
     * @throws ServerResponseException
     * @throws MissingParameterException
     */
    public function switchBlueGreenIndex(string $indexName): void
    {
        $indexConfig = $this->indexRepository->flattenedGet($indexName);
        $this->ensureCorrectIndexSetup($indexConfig);

        if (!$indexConfig->usesBlueGreenIndices()) {
            return;
        }

        $this->log($indexName, '<comment>Switching blue/green index</comment>');
        $oldIndex = $indexConfig->getBlueGreenActiveElasticaIndex();
        $newIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();
        $newIndex->flush();
        $oldIndex->removeAlias($indexConfig->getName());
        $this->log($indexConfig->getName(), 'removed alias from ' . $oldIndex->getName(), ConsoleOutputInterface::VERBOSITY_VERBOSE);
        $newIndex->addAlias($indexConfig->getName());
        $this->log($indexConfig->getName(), 'added alias to ' . $newIndex->getName(), ConsoleOutputInterface::VERBOSITY_VERBOSE);
        $oldIndex->flush();
        $this->postPopulateIndex($indexConfig);
    }

    public function getDocumentCount(IndexInterface $indexConfig): int
    {
        $allowedDocuments = $indexConfig->getAllowedDocuments();
        $count = 0;

        foreach ($allowedDocuments as $document) {
            $documentInstance = $this->documentRepository->get($document);
            $this->documentHelper->setTenantIfNeeded($documentInstance, $indexConfig);

            $listing = $documentInstance->getListingInstance($indexConfig);

            $count += $listing->getTotalCount();
        }

        return $count;
    }

    /**
     * @return \Generator<PopulateIndexMessage>
     */
    public function generateMessagesForIndex(IndexInterface $indexConfig): \Generator
    {
        $this->lockService->initializeProcessCount($indexConfig->getName());
        $allowedDocuments = $indexConfig->getAllowedDocuments();
        $batchSize = $indexConfig->getBatchSize(); // Define the batch size
        $yieldSize = 10;
        $batch = [];
        $this->lockService->initializeProcessCount($indexConfig->getName(), $this->getDocumentCount($indexConfig));

        foreach ($allowedDocuments as $document) {
            $documentInstance = $this->documentRepository->get($document);
            $this->documentHelper->setTenantIfNeeded($documentInstance, $indexConfig);
            $this->consoleOutput->writeln(sprintf('Indexing %s', $document), ConsoleOutputInterface::VERBOSITY_VERBOSE);

            $progressbar = new ProgressBar($this->consoleOutput->isDecorated() ? $this->consoleOutput : new NullOutput());
            $progressbar->setFormat('%message% %current%/%max% [%bar%] %percent:3s%% %elapsed:16s%/%estimated:-16s% %memory:6s%');
            $progressbar->setMessage($document);
            $listing = $documentInstance->getListingInstance($indexConfig);
            $totalCount = $listing->getTotalCount();


            $offset = 0;
            $progressbar->setMaxSteps($totalCount);
            $progressbar->setProgress(0);
            $count = 0;

            while ($offset < $totalCount) {
                $listing->setOffset($offset);
                $listing->setLimit($batchSize);

                foreach ($listing->getData() ?? [] as $dataObject) {
                    $dataObjectId = $dataObject->getId();
                    $progressbar->advance();

                    if (!$documentInstance->shouldIndex($dataObject)) {
                        $this->lockService->messageProcessed($indexConfig->getName());

                        continue;
                    }

                    $batch[] = new PopulateIndexMessage(new CreateDocument(
                        $dataObjectId,
                        $dataObject::class,
                        $document,
                        $indexConfig->getName(),
                    ));

                    $count++;

                    if (count($batch) >= $yieldSize) {
                        yield from $batch;
                        $batch = []; // Reset the batch
                    }
                }

                $offset += $batchSize;
            }

            \Pimcore::collectGarbage();
            $progressbar->finish();

            if ($this->consoleOutput->isDecorated()) {
                $this->consoleOutput->writeln('');
            }

            $this->consoleOutput->writeln('Dispatched ' . $count . ' messages', ConsoleOutputInterface::VERBOSITY_VERBOSE);
        }

        if (count($batch) > 0) {
            yield from $batch;
        }

        yield new PopulateIndexMessage(new SwitchIndex($indexConfig->getName()));

        yield new PopulateIndexMessage(new ReleaseIndexLock($indexConfig->getName(), $this->lockService->getIndexingKey($indexConfig)));
    }

    /**
     * @param ConsoleOutputInterface::VERBOSITY_* $level
     *
     * @return void
     */
    public function setVerbosity(int $level): void
    {
        $this->consoleOutput->setVerbosity($level);
    }

    private function ensureCorrectIndexSetup(IndexInterface $indexConfig): void
    {
        if ($indexConfig->usesBlueGreenIndices()) {
            $this->ensureCorrectBlueGreenIndexSetup($indexConfig);

            return;
        }

        $this->ensureCorrectSimpleIndexSetup($indexConfig);
    }

    private function ensureCorrectSimpleIndexSetup(
        IndexInterface $indexConfig,
    ): void {
        $index = $indexConfig->getElasticaIndex();

        if ($this->shouldDelete && $index->exists()) {
            $index->delete();
            $this->log($indexConfig->getName(), '<comment>Deleted index</comment>');
        }

        if (!$index->exists()) {
            $index->create($indexConfig->getCreateArguments());
            $this->log($indexConfig->getName(), '<comment>Created index</comment>');
        }
    }

    private function ensureCorrectBlueGreenIndexSetup(
        IndexInterface $indexConfig,
    ): void {
        $nonAliasIndex = $this->esClient->getIndex($indexConfig->getName());

        // In case an index with the same name as the blue/green alias exists, delete it
        if (
            $nonAliasIndex->exists()
            && !ElasticsearchResponse::getResponse($this->esClient->indices()->existsAlias(['name' => $indexConfig->getName()]))->asBool()
        ) {
            $nonAliasIndex->delete();
            $this->log($indexConfig->getName(), '<comment>Deleted non-blue/green index to prepare for blue/green usage</comment>');
        }

        foreach (IndexBlueGreenSuffix::cases() as $suffix) {
            $name = $indexConfig->getName() . $suffix->value;
            $aliasIndex = $this->esClient->getIndex($name);

            if ($this->shouldDelete && $aliasIndex->exists()) {
                $aliasIndex->delete();
                $this->log($indexConfig->getName(), sprintf('<comment>Deleted blue/green index with alias %s</comment>', $name));
            }

            if (!$aliasIndex->exists()) {
                $aliasIndex->create($indexConfig->getCreateArguments());
                $this->log($indexConfig->getName(), sprintf('<comment>Created blue/green index with alias %s</comment>', $name));
            }
        }

        try {
            $indexConfig->getBlueGreenActiveSuffix();
        } catch (BlueGreenIndicesIncorrectlySetupException) {
            $this->esClient->getIndex($indexConfig->getName() . IndexBlueGreenSuffix::BLUE->value)
                ->addAlias($indexConfig->getName());
        }

        $this->log($indexConfig->getName(), '<comment>Ensured indices are correctly set up with alias</comment>');
    }

    private function checkIndex(
        IndexInterface $indexConfig,
        bool $ignoreCooldown = false,
        bool $ignoreLock = false,
    ): void {
        $cooldownKey = $this->lockService->getKey($indexConfig->getName(), 'cooldown');
        $cooldownLock = $this->lockService->createLockFromKey($cooldownKey, ttl: 0);
        $messagesProcessed = $this->lockService->getActualMessageCount($indexConfig->getName()) === 0;
        $processingLock = $this->lockService->getIndexingLock($indexConfig);

        if ($this->getDocumentCount($indexConfig) === 0) {
            throw new AlreadyInProgressException(AlreadyInProgressException::TYPE_NO_DOCUMENTS);
        }

        if (!$messagesProcessed) {
            throw new AlreadyInProgressException(AlreadyInProgressException::TYPE_PROCESSING_MESSAGES);
        }

        if (!$ignoreCooldown && !$cooldownLock->acquire()) {
            throw new AlreadyInProgressException(AlreadyInProgressException::TYPE_COOLDOWN);
        }

        if (!$ignoreLock && !$processingLock->acquire()) {
            throw new AlreadyInProgressException(AlreadyInProgressException::TYPE_PROCESSING);
        }

        $cooldownLock->release();
    }

    private function log(string $indexName, string $message, int $verbosityLevel = ConsoleOutputInterface::VERBOSITY_NORMAL): void
    {
        $this->consoleOutput->writeln(sprintf('<info>%s</info>-> %s', $indexName, $message), $verbosityLevel);
    }
}
