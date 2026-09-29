<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Pimcore\Db;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Listing as AssetListing;
use Pimcore\Model\DataObject\Listing as DataObjectListing;
use Pimcore\Model\Document;
use Pimcore\Model\Document\Listing as DocumentListing;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Valantic\ElasticaBridgeBundle\Enum\PopulationSource;
use Valantic\ElasticaBridgeBundle\Exception\Index\PopulationNotStartedException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Handler\CreateDocumentHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocumentMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Messenger\Message\SwitchIndex;
use Valantic\ElasticaBridgeBundle\Messenger\Message\TriggerSingleIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Middleware\SyncTransportDetectionInterface;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PreAddDocumentToQueueEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreExecuteEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreProcessMessagesEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreSwitchIndexEvent;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class PopulateIndexService
{
    private bool $shouldDelete = false;

    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly IndexSetupService $indexSetupService,
        private readonly LockService $lockService,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentHelper $documentHelper,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MessageBusInterface $messengerBusElasticaBridge,
        private readonly ConsoleOutputInterface $consoleOutput,
        private readonly PopulateLogger $logger,
    ) {
    }

    /**
     * @return \Generator<PopulateIndexMessage|Envelope>
     */
    public function processScheduler(): \Generator
    {
        foreach ($this->indexRepository->flattenedAll() as $indexConfig) {
            try {
                $this->eventDispatcher->dispatch(new PreExecuteEvent($indexConfig, PopulationSource::SCHEDULER), ElasticaBridgeEvents::PRE_EXECUTE);
                $this->checkIndex($indexConfig);

                $this->indexSetupService->setupIndex($indexConfig, $this->shouldDelete);

                foreach ($this->generateMessagesForIndex($indexConfig) as $message) {
                    yield (new Envelope($message))->with(new HandlerArgumentsStamp([
                        SyncTransportDetectionInterface::SYNCHRONOUS_ARGUMENT => false,
                    ]));
                }
            } catch (PopulationNotStartedException $e) {
                if (!$e->isSilentModeEnabled()) {
                    $this->logger->log($indexConfig->getName(), '<fg=red>' . $e->getMessage() . '</>');
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

        $this->eventDispatcher->dispatch(new PreExecuteEvent($indexConfig, PopulationSource::API), ElasticaBridgeEvents::PRE_EXECUTE);

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

            $this->indexSetupService->setupIndex($indexConfig, $this->shouldDelete);

            if (!$populate) {
                return;
            }

            yield from $this->generateMessagesForIndex($indexConfig, $ignoreCooldown);
        } catch (PopulationNotStartedException $populationNotStartedException) {
            if ($populationNotStartedException->isSilentModeEnabled()) {
                throw $populationNotStartedException;
            }

            if (!is_string($indexConfig)) {
                $indexConfig = $indexConfig->getName();
            }

            $this->logger->log($indexConfig, '<fg=red>' . $populationNotStartedException->getMessage() . '</>');

            throw $populationNotStartedException;
        }
    }

    public function setShouldDelete(bool $shouldDelete): self
    {
        $this->shouldDelete = $shouldDelete;

        return $this;
    }

    public function getDocumentCount(IndexInterface $indexConfig): int
    {
        $allowedDocuments = $indexConfig->getAllowedDocuments();
        $count = 0;

        foreach ($allowedDocuments as $document) {
            $count += $this->getListing($document, $indexConfig)->getTotalCount();
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

        if ($documentCount === 0) {
            $allowedDocuments = [];
        }

        foreach ($allowedDocuments as $document) {
            $this->consoleOutput->writeln(sprintf('Indexing %s', $document), ConsoleOutputInterface::VERBOSITY_VERBOSE);

            $progressbar = new ProgressBar($this->consoleOutput->isDecorated() ? $this->consoleOutput : new NullOutput());
            $progressbar->setFormat('%message% %current%/%max% [%bar%] %percent:3s%% %elapsed:16s%/%estimated:-16s% %memory:6s%');
            $progressbar->setMessage($document);
            $listing = $this->getListing($document, $indexConfig);
            $totalCount = $listing->getTotalCount();

            if ($totalCount === 0) {
                $this->consoleOutput->writeln(sprintf('No documents found for %s', $document), ConsoleOutputInterface::VERBOSITY_VERBOSE);

                continue;
            }

            $offset = 0;
            $progressbar->setMaxSteps($totalCount);
            $progressbar->setProgress(0);
            $count = 0;

            while ($offset < $totalCount) {
                $listing->setOffset($offset);
                $listing->setLimit($batchSize);
                $ids = $listing->loadIdList();

                foreach ($ids ?? [] as $dataObjectId) {
                    $progressbar->advance();

                    $elementType = $this->getElementType($listing, $dataObjectId);

                    if ($elementType === null) {
                        continue;
                    }

                    $batch[] = new PopulateIndexMessage(new CreateDocumentMessage(
                        $dataObjectId,
                        $elementType,
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
            $this->consoleOutput->writeln('Dispatched ' . count($batch) . ' remaining messages', ConsoleOutputInterface::VERBOSITY_VERBOSE);
        }

        if ($messageGenerated) {
            yield new PopulateIndexMessage(new SwitchIndex($indexConfig->getName(), !$ignoreCooldown));
        } elseif (!$ignoreCooldown) {
            $this->lockService->initiateCooldown($indexConfig->getName());
        }

        yield new PopulateIndexMessage(new ReleaseIndexLock($indexConfig->getName(), $this->lockService->getIndexingKey($indexConfig)));
    }

    public function isPopulating(IndexInterface $indexConfig): bool
    {
        return $this->lockService->isIndexingLocked($indexConfig);
    }

    private function getListing(string $document, IndexInterface $indexConfig): DataObjectListing|DocumentListing|AssetListing
    {
        $documentInstance = $this->documentRepository->get($document);
        $this->documentHelper->setTenantIfNeeded($documentInstance, $indexConfig);

        return $documentInstance->getListingInstance($indexConfig);
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
        $messagesProcessed = $this->eventDispatcher->dispatch(new PreSwitchIndexEvent($indexConfig), ElasticaBridgeEvents::PRE_SWITCH_INDEX)->getRemainingMessages() === 0;
        $processingLock = $this->lockService->getIndexingLock($indexConfig, autorelease: !$keepProcessingLock);

        if ($this->getDocumentCount($indexConfig) === 0) {
            throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_NO_DOCUMENTS);
        }

        if (!$ignoreQueueLock && !$queueLock->acquire()) {
            throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_PROCESSING);
        }

        try {
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
        } catch (PopulationNotStartedException $exception) {
            // population is not starting, so do not keep the locks acquired above
            if (!$ignoreQueueLock) {
                $queueLock->release();
            }

            if ($cooldownLock->isAcquired()) {
                $cooldownLock->release();
            }

            throw $exception;
        }
    }

    /**
     * @return class-string|null null if the element no longer exists
     */
    private function getElementType(AssetListing|DataObjectListing|DocumentListing $listing, mixed $dataObjectId): ?string
    {
        if ($listing instanceof AssetListing) {
            $asset = Asset::getById($dataObjectId);

            return $asset instanceof Asset ? $asset::class : null;
        }

        if ($listing instanceof DocumentListing) {
            $document = Document::getById($dataObjectId);

            return $document instanceof Document ? $document::class : null;
        }

        $tableName = $listing->getDao()->getTableName();
        $query = sprintf('SELECT %s FROM %s WHERE id = ?', 'className', $tableName);
        $result = Db::getConnection()->fetchOne($query, [$dataObjectId]);

        if ($result === false) {
            throw new \RuntimeException(sprintf('DataObject with ID %s not found in table %s', $dataObjectId, $tableName));
        }

        $className = 'Pimcore\\Model\\DataObject\\' . ucfirst((string) $result);

        if (!class_exists($className)) {
            throw new \RuntimeException(sprintf('DataObject class %s for ID %s does not exist', $className, $dataObjectId));
        }

        return $className;
    }
}
