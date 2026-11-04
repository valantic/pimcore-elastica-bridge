<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastica\Document;
use Elastica\Exception\NotFoundException;
use Elastica\Index;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\Term;
use Elastica\Result;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Enum\ElementInIndexOperation;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElementInIndex;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\RefreshedElementEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\RefreshedElementInIndexEvent;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class PropagateChanges
{
    /**
     * Upper bound for the number of documents (i.e. contexts) of a single element in an index with contexts.
     */
    private const MAX_CONTEXT_DOCUMENTS_PER_ELEMENT = 10_000;

    private static bool $isPropagationStopped = false;

    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly DocumentHelper $documentHelper,
        private readonly MessageBusInterface $messageBus,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public static function stopPropagation(): void
    {
        self::$isPropagationStopped = true;
    }

    private static function getOperation(bool $isPresent, bool $shouldIndex): ElementInIndexOperation
    {
        return match (true) {
            !$isPresent && $shouldIndex => ElementInIndexOperation::INSERT,
            $isPresent && $shouldIndex => ElementInIndexOperation::UPDATE,
            $isPresent && !$shouldIndex => ElementInIndexOperation::DELETE,
            default => ElementInIndexOperation::NOTHING,
        };
    }

    /**
     * Whenever an event occurs, a decision needs to be made:
     * 1. Which indices might need to be updated?
     * 2. Does the element need to be in Elasticsearch or not?
     * 3. Are there Elasticsearch documents to be created/updated or deleted?
     */
    public function handle(AbstractElement $element): void
    {
        $indices = $this->matchingIndicesForElement($this->indexRepository->flattenedAll(), $element);

        $event = new RefreshedElementEvent($element, $indices);

        if (!self::$isPropagationStopped) {
            $this->eventDispatcher->dispatch($event, ElasticaBridgeEvents::PRE_REFRESH_ELEMENT);
        }

        $indices = $event->getIndices();

        foreach ($indices as $index) {
            $this->messageBus->dispatch(
                new RefreshElementInIndex(
                    $element,
                    $index->getName(),
                    self::$isPropagationStopped || $event->isPropagationStopped(),
                ),
            );
        }

        if (!self::$isPropagationStopped && !$event->isPropagationStopped()) {
            $this->eventDispatcher->dispatch($event, ElasticaBridgeEvents::POST_REFRESH_ELEMENT);
        }
    }

    public function handleIndex(
        AbstractElement $element,
        IndexInterface $index,
        ?Index $elasticaIndex = null,
    ): void {
        $this->doHandleIndex($element, $index, $elasticaIndex ?? $index->getElasticaIndex());
    }

    private function doHandleIndex(
        AbstractElement $element,
        IndexInterface $index,
        Index $elasticaIndex,
    ): void {
        $document = $index->findDocumentInstanceByPimcore($element);

        if (!$document instanceof DocumentInterface) {
            return;
        }

        $this->documentHelper->setTenantIfNeeded($document, $index);

        if (
            !in_array($document::class, $index->subscribedDocuments(), true)
            || ($element->getType() === AbstractObject::OBJECT_TYPE_VARIANT && !$document->treatObjectVariantsAsDocuments())
        ) {
            $this->documentHelper->resetTenantIfNeeded($document, $index);

            return;
        }

        try {
            if ($index->getContexts() !== []) {
                $this->handleIndexWithContexts($element, $index, $elasticaIndex, $document);
            } else {
                $this->handleIndexWithoutContexts($element, $index, $elasticaIndex, $document);
            }
        } finally {
            $this->documentHelper->resetTenantIfNeeded($document, $index);
        }
    }

    /**
     * @param DocumentInterface<AbstractElement> $document
     */
    private function handleIndexWithoutContexts(
        AbstractElement $element,
        IndexInterface $index,
        Index $elasticaIndex,
        DocumentInterface $document,
    ): void {
        $isPresent = $this->isIdInIndex($document::getElasticsearchId($element), $elasticaIndex);
        $shouldIndex = $document->shouldIndex($element);

        $operation = self::getOperation($isPresent, $shouldIndex);

        $this->dispatchRefreshEvents($element, $index, $elasticaIndex, $operation, fn () => match ($operation) {
            ElementInIndexOperation::INSERT => $this->addElementToIndex($element, $elasticaIndex, $document),
            ElementInIndexOperation::UPDATE => $this->updateElementInIndex($element, $elasticaIndex, $document),
            ElementInIndexOperation::DELETE => $this->deleteElementFromIndex($element, $elasticaIndex, $document),
            ElementInIndexOperation::NOTHING => null,
        });
    }

    /**
     * Replaces all documents of the element: documents of its current contexts are written, documents of contexts it no longer has are deleted.
     *
     * @param DocumentInterface<AbstractElement> $document
     */
    private function handleIndexWithContexts(
        AbstractElement $element,
        IndexInterface $index,
        Index $elasticaIndex,
        DocumentInterface $document,
    ): void {
        // Searches only see refreshed documents; without this, documents written within the refresh interval
        // (or during population, when the inactive index may not refresh at all) would be missed.
        $elasticaIndex->refresh();
        $existingIds = $this->findDocumentIdsOfElement($element, $elasticaIndex, $document);
        $contexts = $this->documentHelper->resolveDocumentContexts($document, $element, $index);

        $operation = self::getOperation($existingIds !== [], $contexts !== []);

        $this->dispatchRefreshEvents($element, $index, $elasticaIndex, $operation, function () use ($element, $elasticaIndex, $document, $contexts, $existingIds): void {
            $currentIds = [];

            if ($contexts !== []) {
                // Normalized only now so that PRE_REFRESH_ELEMENT_IN_INDEX listeners run first, as for indices without contexts.
                $esDocuments = $this->documentHelper->elementToContextDocuments($document, $element, $contexts);
                // Written before deleting the stale documents, so a failed write never leaves the element without documents.
                $elasticaIndex->addDocuments($esDocuments);
                $currentIds = array_map(static fn (Document $esDocument): string => (string) $esDocument->getId(), $esDocuments);
            }

            $staleIds = array_values(array_diff($existingIds, $currentIds));

            if ($staleIds !== []) {
                $elasticaIndex->deleteDocuments(array_map(static fn (string $id): Document => new Document($id), $staleIds));
            }
        });
    }

    /**
     * @param \Closure(): void $apply
     */
    private function dispatchRefreshEvents(
        AbstractElement $element,
        IndexInterface $index,
        Index $elasticaIndex,
        ElementInIndexOperation $operation,
        \Closure $apply,
    ): void {
        $event = new RefreshedElementInIndexEvent($element, $index, $elasticaIndex, $operation);

        if (!self::$isPropagationStopped) {
            $this->eventDispatcher->dispatch($event, ElasticaBridgeEvents::PRE_REFRESH_ELEMENT_IN_INDEX);
        }

        $apply();

        if (!self::$isPropagationStopped && !$event->isPropagationStopped()) {
            $this->eventDispatcher->dispatch($event, ElasticaBridgeEvents::POST_REFRESH_ELEMENT_IN_INDEX);
        }
    }

    /**
     * @param DocumentInterface<AbstractElement> $document
     *
     * @return string[]
     */
    private function findDocumentIdsOfElement(
        AbstractElement $element,
        Index $elasticaIndex,
        DocumentInterface $document,
    ): array {
        // __id alone is not unique: e.g. an asset and a data object can share the same Pimcore ID.
        $query = (new Query(
            (new BoolQuery())
                ->addFilter(new Term([DocumentInterface::META_ID => $element->getId()]))
                ->addFilter(new Term([DocumentInterface::META_TYPE => $document->getType()->value])),
        ))
            ->setSource(false)
            ->setSize(self::MAX_CONTEXT_DOCUMENTS_PER_ELEMENT)
        ;

        return array_map(static fn (Result $result): string => (string) $result->getId(), $elasticaIndex->search($query)->getResults());
    }

    /**
     * @param DocumentInterface<AbstractElement> $document
     */
    private function addElementToIndex(
        AbstractElement $element,
        Index $index,
        DocumentInterface $document,
    ): void {
        $elasticaDocument = $this->documentHelper->elementToDocument($document, $element);
        $index->addDocument($elasticaDocument);
    }

    /**
     * @param DocumentInterface<AbstractElement> $document
     */
    private function updateElementInIndex(
        AbstractElement $element,
        Index $index,
        DocumentInterface $document,
    ): void {
        $elasticaDocument = $this->documentHelper->elementToDocument($document, $element);
        // updateDocument() allows partial updates, hence the full replace here
        $index->addDocument($elasticaDocument);
    }

    /**
     * @param DocumentInterface<AbstractElement> $document
     */
    private function deleteElementFromIndex(
        AbstractElement $element,
        Index $index,
        DocumentInterface $document,
    ): void {
        $elasticsearchId = $document::getElasticsearchId($element);
        $index->deleteById($elasticsearchId);
    }

    /**
     * Returns an array of indices that could contain $element.
     *
     * @param \Generator<string,IndexInterface,void,void> $indices
     *
     * @return IndexInterface[]
     */
    private function matchingIndicesForElement(
        \Generator $indices,
        AbstractElement $element,
    ): array {
        $matching = [];

        foreach ($indices as $index) {
            /** @var IndexInterface $index */
            if ($index->isElementAllowedInIndex($element)) {
                $matching[] = $index;
            }
        }

        return $matching;
    }

    /**
     * Checks whether a given ID is in an index.
     */
    private function isIdInIndex(string $id, Index $index): bool
    {
        try {
            $index->getDocument($id);
        } catch (NotFoundException) {
            return false;
        }

        return true;
    }
}
