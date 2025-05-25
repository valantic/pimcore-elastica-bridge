<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;
use Valantic\ElasticaBridgeBundle\Exception\Index\PopulationNotStartedException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Handler\CreateDocumentHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocumentMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Messenger\Message\SwitchIndex;
use Valantic\ElasticaBridgeBundle\Messenger\Message\TriggerSingleIndexMessage;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PostDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreAddDocumentToQueueEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreExecuteEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreProcessMessagesEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreSwitchIndexEvent;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Util\ElasticsearchResponse;

class PopulateIndexService
{
    private bool $shouldDelete = false;
    /**
     * @var string[]
     */
    private array $messages = [];

    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly ElasticsearchClient $esClient,
        private readonly LockService $lockService,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentHelper $documentHelper,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MessageBusInterface $messengerBusElasticaBridge,
        private readonly ConsoleOutputInterface $consoleOutput,
    ) {}

    /**
     * @return \Generator<PopulateIndexMessage|Envelope>
     */
    public function processScheduler(): \Generator
    {

        foreach ($this->indexRepository->flattenedAll() as $indexConfig) {
            try {
                $this->eventDispatcher->dispatch(new PreExecuteEvent($indexConfig, PreExecuteEvent::SOURCE_SCHEDULER), ElasticaBridgeEvents::PRE_EXECUTE);
                $this->checkIndex($indexConfig);

                $this->setupIndex($indexConfig);

                foreach ($this->generateMessagesForIndex($indexConfig) as $message) {
                    yield (new Envelope($message))->with(new HandlerArgumentsStamp([
                        'synchronous' => false,
                    ]));
                }
            } catch (PopulationNotStartedException $e) {
                if (!$e->isSilentModeEnabled()) {
                    $this->log($indexConfig->getName(), '<fg=red>' . $e->getMessage() . '</>');
                }

                continue;
            }
        }
    }

    public function processApi(
        IndexInterface|string $indexConfig,
        bool $populate = false,
        bool $ignoreLock = false,
        bool $ignoreCooldown = false,
    ): void {
        if (is_string($indexConfig)) {
            $indexConfig = $this->indexRepository->flattenedGet($indexConfig);
        }
        $this->eventDispatcher->dispatch(new PreExecuteEvent($indexConfig, PreExecuteEvent::SOURCE_API), ElasticaBridgeEvents::PRE_EXECUTE);

        $this->checkIndex($indexConfig, $ignoreCooldown, $ignoreLock, false, ignoreQueueLock: false);

        $key = $this->lockService->getKey($indexConfig->getName(), 'queue');
        $this->messengerBusElasticaBridge->dispatch(new TriggerSingleIndexMessage($indexConfig->getName(), $populate, $ignoreCooldown, $ignoreLock, $key));
    }

    /**
     * @return \Generator<PopulateIndexMessage>
     */
    public function triggerSingleIndex(
        IndexInterface|string $indexConfig,
        bool $populate = false,
        bool $ignoreLock = false,
        bool $ignoreCooldown = false,
    ): \Generator {
        try {
            if (is_string($indexConfig)) {
                $indexConfig = $this->indexRepository->flattenedGet($indexConfig);
            }

            $this->checkIndex($indexConfig, $ignoreCooldown, $ignoreLock, $populate);

            $this->setupIndex($indexConfig);

            if (!$populate) {
                return;
            }

            yield from $this->generateMessagesForIndex($indexConfig, $ignoreCooldown);
        } catch (PopulationNotStartedException $e) {
            if ($e->isSilentModeEnabled()) {
                throw $e;
            }

            if (!is_string($indexConfig)) {
                $indexConfig = $indexConfig->getName();
            }

            $this->log($indexConfig, '<fg=red>' . $e->getMessage() . '</>');

            throw $e;
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
    public function generateMessagesForIndex(IndexInterface $indexConfig, bool $ignoreCooldown = false): \Generator
    {
        $allowedDocuments = $indexConfig->getAllowedDocuments();
        $batchSize = $indexConfig->getBatchSize(); // Define the batch size
        $yieldSize = 10;
        $messageGenerated = false;
        $batch = [];
        $documentCount = $this->getDocumentCount($indexConfig);
        $this->eventDispatcher->dispatch(new PreProcessMessagesEvent($indexConfig, $documentCount), ElasticaBridgeEvents::PRE_PROCESS_MESSAGES_EVENT);
        CreateDocumentHandler::$messageCount = $documentCount;

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
                        $this->eventDispatcher->dispatch(new PostDocumentCreateEvent($indexConfig, $dataObject->getType(), $dataObjectId, $dataObject, skipped: true), ElasticaBridgeEvents::POST_DOCUMENT_CREATE);
                        CreateDocumentHandler::$messageCount--;

                        continue;
                    }

                    $batch[] = new PopulateIndexMessage(new CreateDocumentMessage(
                        $dataObjectId,
                        $dataObject::class,
                        $document,
                        $indexConfig->getName(),
                    ));
                    $messageGenerated = true;
                    $count++;

                    if (count($batch) >= $yieldSize) {
                        $this->eventDispatcher->dispatch(new PreAddDocumentToQueueEvent($indexConfig, count($batch)), ElasticaBridgeEvents::PRE_ADD_DOCUMENT_TO_QUEUE);

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
            $this->eventDispatcher->dispatch(new PreAddDocumentToQueueEvent($indexConfig, count($batch)), ElasticaBridgeEvents::PRE_ADD_DOCUMENT_TO_QUEUE);

            yield from $batch;
            $this->consoleOutput->writeln('Dispatched ' . count($batch) . 'remaining messages', ConsoleOutputInterface::VERBOSITY_VERBOSE);
        }

        if ($messageGenerated) {
            yield new PopulateIndexMessage(new SwitchIndex($indexConfig->getName(), !$ignoreCooldown));
        } elseif (!$ignoreCooldown) {
            $this->lockService->initiateCooldown($indexConfig->getName());
        }

        yield new PopulateIndexMessage(new ReleaseIndexLock($indexConfig->getName(), $this->lockService->getIndexingKey($indexConfig)));
    }

    /**
     * @phpstan-param ConsoleOutputInterface::VERBOSITY_* $level
     */
    public function setVerbosity(int $level): self
    {
        $this->consoleOutput->setVerbosity($level);

        return $this;
    }

    public function isPopulating(IndexInterface $indexConfig): bool
    {
        try {
            $this->checkIndex($indexConfig, true, false, false);
        } catch (PopulationNotStartedException) {
            return false;
        }

        return true;
    }

    /**
     * @phpstan-param OutputInterface::VERBOSITY_* $verbosityLevel
     */
    public function log(string $indexName, string $message, int $verbosityLevel = OutputInterface::VERBOSITY_NORMAL): void
    {
        $this->messages[] = sprintf('%s: %s', $indexName, $message);
        $this->consoleOutput->writeln(sprintf('<info>%s</info>-> %s', $indexName, $message), $verbosityLevel);
    }

    /**
     * @return string[]
     */
    public function getLog(): array
    {
        return $this->messages;
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
        bool $keepProcessingLock = true,
        bool $ignoreQueueLock = true,
    ): void {
        $cooldownKey = $this->lockService->getKey($indexConfig->getName(), 'cooldown');
        $queueKey = $this->lockService->getKey($indexConfig->getName(), 'queue');
        $queueLock = $this->lockService->createLockFromKey($queueKey);
        $cooldownLock = $this->lockService->createLockFromKey($cooldownKey, ttl: 0);
        $messagesProcessed = $this->eventDispatcher->dispatch(new PreSwitchIndexEvent($indexConfig))->getRemainingMessages() === 0;
        $processingLock = $this->lockService->getIndexingLock($indexConfig, autorelease: !$keepProcessingLock);

        if ($this->getDocumentCount($indexConfig) === 0) {
            throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_NO_DOCUMENTS);
        }

        if (!$ignoreQueueLock && !$queueLock->acquire()) {
            throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_PROCESSING);
        }

        if (!$ignoreCooldown && !$cooldownLock->acquire()) {
            throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_COOLDOWN);
        }

        if (!$messagesProcessed) {
            throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_PROCESSING_MESSAGES);
        }

        $cooldownLock->release();

        if (!$ignoreLock && !$processingLock->acquire()) {
            throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_PROCESSING);
        }
    }
}
