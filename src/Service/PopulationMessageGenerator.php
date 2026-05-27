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
        $batchSize = $indexConfig->getBatchSize();
        $messageBatchSize = $indexConfig->getMessageBatchSize();
        // Number of PopulateIndexMessages to accumulate before yielding to the transport.
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
            // IDs accumulated for the current CreateDocumentMessage batch.
            $idBatch = [];
            $batchType = null;

            while ($offset < $totalCount) {
                $listing->setOffset($offset);
                $listing->setLimit($batchSize);
                $ids = $listing->loadIdList();
                $typeMap = $this->getElementTypes($listing, $ids ?? []);

                foreach ($ids ?? [] as $dataObjectId) {
                    $progressbar->advance();

                    $elementType = $typeMap[$dataObjectId] ?? null;

                    if ($elementType === null) {
                        continue;
                    }

                    $batchType ??= $elementType;
                    $idBatch[] = $dataObjectId;
                    $count++;

                    if (count($idBatch) >= $messageBatchSize) {
                        $batch[] = new PopulateIndexMessage(new CreateDocumentMessage(
                            $idBatch,
                            $batchType,
                            $document,
                            $indexConfig->getName(),
                        ));
                        $messageGenerated = true;
                        $idBatch = [];
                        $batchType = null;

                        if (count($batch) >= $yieldSize) {
                            $this->eventDispatcher->dispatch(new PreAddDocumentToQueueEvent($indexConfig, count($batch)), ElasticaBridgeEvents::PRE_ADD_DOCUMENT_TO_QUEUE);

                            yield from $batch;
                            $batch = [];
                        }
                    }
                }

                $offset += $batchSize;
            }

            // Flush remaining IDs for this document class.
            if ($batchType !== null) {
                $batch[] = new PopulateIndexMessage(new CreateDocumentMessage(
                    $idBatch,
                    $batchType,
                    $document,
                    $indexConfig->getName(),
                ));
                $messageGenerated = true;
            }

            \Pimcore::collectGarbage();
            $progressbar->finish();

            if ($this->consoleOutput->isDecorated()) {
                $this->consoleOutput->writeln('');
            }

            $this->consoleOutput->writeln('Dispatched ' . $count . ' elements in messages', ConsoleOutputInterface::VERBOSITY_VERBOSE);
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
     * Resolve element types for a batch of IDs in a single query (DataObject listings)
     * or via getById for Asset/Document listings.
     *
     * @param int[] $ids
     *
     * @return array<int, class-string> IDs of Assets/Documents that no longer exist are omitted
     */
    private function getElementTypes(AssetListing|DataObjectListing|DocumentListing $listing, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        if ($listing instanceof AssetListing || $listing instanceof DocumentListing) {
            $baseClass = $listing instanceof AssetListing ? Asset::class : Document::class;
            $types = [];

            foreach ($ids as $id) {
                $element = $baseClass::getById($id);

                if ($element instanceof $baseClass) {
                    $types[$id] = $element::class;
                }
            }

            return $types;
        }

        $tableName = $listing->getDao()->getTableName();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::getConnection()->fetchAllAssociative(
            sprintf('SELECT id, className FROM %s WHERE id IN (%s)', $tableName, $placeholders),
            $ids,
        );
        $types = [];

        foreach ($rows as $row) {
            $className = 'Pimcore\\Model\\DataObject\\' . ucfirst((string) $row['className']);

            if (!class_exists($className)) {
                throw new \RuntimeException(sprintf('DataObject class %s for ID %s does not exist', $className, $row['id']));
            }

            $types[(int) $row['id']] = $className;
        }

        foreach ($ids as $id) {
            if (!isset($types[$id])) {
                throw new \RuntimeException(sprintf('DataObject with ID %s not found in table %s', $id, $tableName));
            }
        }

        return $types;
    }
}
