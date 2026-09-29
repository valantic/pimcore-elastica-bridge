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
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocumentMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Messenger\Message\SwitchIndex;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PreAddDocumentToQueueEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreProcessMessagesEvent;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;

/**
 * Turns the elements of an index into the messages that populate it.
 */
class PopulationMessageGenerator
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentHelper $documentHelper,
        private readonly LockService $lockService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConsoleOutputInterface $consoleOutput,
        private readonly PopulationProgress $populationProgress,
    ) {
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
    public function generate(IndexInterface $indexConfig, bool $ignoreCooldown = false): \Generator
    {
        $allowedDocuments = $indexConfig->getAllowedDocuments();
        $batchSize = $indexConfig->getBatchSize(); // Define the batch size
        $yieldSize = 10;
        $messageGenerated = false;
        $batch = [];
        $documentCount = $this->getDocumentCount($indexConfig);
        $this->eventDispatcher->dispatch(new PreProcessMessagesEvent($indexConfig, $documentCount), ElasticaBridgeEvents::PRE_PROCESS_MESSAGES_EVENT);
        $this->populationProgress->start($documentCount);

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

    private function getListing(string $document, IndexInterface $indexConfig): DataObjectListing|DocumentListing|AssetListing
    {
        $documentInstance = $this->documentRepository->get($document);
        $this->documentHelper->setTenantIfNeeded($documentInstance, $indexConfig);

        return $documentInstance->getListingInstance($indexConfig);
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
