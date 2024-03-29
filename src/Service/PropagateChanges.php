<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastica\Exception\NotFoundException;
use Elastica\Index;
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
    private static bool $isPropagationStopped = false;

    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly DocumentHelper $documentHelper,
        private readonly MessageBusInterface $messageBus,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

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

        foreach ($indices as $index) {
            $this->messageBus->dispatch(
                new RefreshElementInIndex(
                    $element,
                    $index->getName(),
                    self::$isPropagationStopped || $event->isPropagationStopped()
                )
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

    public static function stopPropagation(): void
    {
        self::$isPropagationStopped = true;
    }

    private function doHandleIndex(
        AbstractElement $element,
        IndexInterface $index,
        Index $elasticaIndex,
    ): void {
        // TODO: actually use $elasticaIndex
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

        $isPresent = $this->isIdInIndex($document::getElasticsearchId($element), $elasticaIndex);
        $shouldIndex = $document->shouldIndex($element);

        $operation = match (true) {
            !$isPresent && $shouldIndex => ElementInIndexOperation::INSERT,
            $isPresent && $shouldIndex => ElementInIndexOperation::UPDATE,
            $isPresent && !$shouldIndex => ElementInIndexOperation::DELETE,
            default => ElementInIndexOperation::NOTHING,
        };

        $event = new RefreshedElementInIndexEvent($element, $index, $elasticaIndex, $operation);

        if (!self::$isPropagationStopped) {
            $this->eventDispatcher->dispatch($event, ElasticaBridgeEvents::PRE_REFRESH_ELEMENT_IN_INDEX);
        }

        match ($operation) {
            ElementInIndexOperation::INSERT => $this->addElementToIndex($element, $elasticaIndex, $document),
            ElementInIndexOperation::UPDATE => $this->updateElementInIndex($element, $elasticaIndex, $document),
            ElementInIndexOperation::DELETE => $this->deleteElementFromIndex($element, $elasticaIndex, $document),
            ElementInIndexOperation::NOTHING => null,
        };

        if (!self::$isPropagationStopped && !$event->isPropagationStopped()) {
            $this->eventDispatcher->dispatch($event, ElasticaBridgeEvents::POST_REFRESH_ELEMENT_IN_INDEX);
        }

        $this->documentHelper->resetTenantIfNeeded($document, $index);
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
