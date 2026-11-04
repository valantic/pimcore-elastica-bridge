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

    /**
     * Groups IDs by element type into chunks of at most $size, as a CreateDocumentMessage carries a single element type.
     *
     * @param iterable<int, class-string> $elementTypes element ID => element type
     *
     * @return \Generator<int, array{class-string, int[]}>
     */
    private static function chunkIdsByType(iterable $elementTypes, int $size): \Generator
    {
        $chunks = [];

        foreach ($elementTypes as $id => $elementType) {
            $chunks[$elementType][] = $id;

            if (count($chunks[$elementType]) >= $size) {
                yield [$elementType, $chunks[$elementType]];
                unset($chunks[$elementType]);
            }
        }

        foreach ($chunks as $elementType => $ids) {
            yield [$elementType, $ids];
        }
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

            $progressbar->setMaxSteps($totalCount);
            $progressbar->setProgress(0);
            $count = 0;
            $elementTypes = $this->loadElementTypes($listing, $totalCount, $batchSize, $progressbar);

            foreach (self::chunkIdsByType($elementTypes, $messageBatchSize) as [$elementType, $ids]) {
                $batch[] = new PopulateIndexMessage(new CreateDocumentMessage(
                    $ids,
                    $elementType,
                    $document,
                    $indexConfig->getName(),
                ));
                $messageGenerated = true;
                $count += count($ids);

                if (count($batch) >= $yieldSize) {
                    $this->eventDispatcher->dispatch(new PreAddDocumentToQueueEvent($indexConfig, count($batch)), ElasticaBridgeEvents::PRE_ADD_DOCUMENT_TO_QUEUE);

                    yield from $batch;
                    $batch = [];
                }
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
     * @return \Generator<int, class-string> element ID => element type, skipping elements that no longer exist
     */
    private function loadElementTypes(
        AssetListing|DataObjectListing|DocumentListing $listing,
        int $totalCount,
        int $batchSize,
        ProgressBar $progressbar,
    ): \Generator {
        for ($offset = 0; $offset < $totalCount; $offset += $batchSize) {
            $listing->setOffset($offset);
            $listing->setLimit($batchSize);
            $ids = $listing->loadIdList() ?? [];
            $types = $this->getElementTypes($listing, $ids);

            foreach ($ids as $id) {
                $progressbar->advance();

                if (isset($types[$id])) {
                    yield $id => $types[$id];
                }
            }
        }
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
