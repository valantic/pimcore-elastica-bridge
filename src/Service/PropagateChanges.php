<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastica\Exception\NotFoundException;
use Elastica\Index;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElementInIndex;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class PropagateChanges
{
    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly DocumentHelper $documentHelper,
        private readonly MessageBusInterface $messageBus,
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

        foreach ($indices as $index) {
            $this->messageBus->dispatch(new RefreshElementInIndex($element, $index->getName()));
        }
    }

    public function handleIndex(
        AbstractElement $element,
        IndexInterface $index,
        ?Index $elasticaIndex = null,
    ): void {
        $this->doHandleIndex($element, $index, $elasticaIndex ?? $index->getElasticaIndex());
    }

    /**
     * @return void
     */
    public function handleRelatedObjects(AbstractElement $element): void
    {
        $indices = $this->matchingIndicesForElement($this->indexRepository->flattenedAll(), $element);
        $relatedObjects = [];

        foreach ($indices as $index) {
            $document = $index->findDocumentInstanceByPimcore($element);
            $relatedObjects += $document?->relatedObjects($element);
        }

        foreach ($relatedObjects as $relatedObject) {
            $this->handle($relatedObject);
        }
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

        if ($document->shouldIndex($element)) {
            if ($isPresent) {
                $this->updateElementInIndex($element, $elasticaIndex, $document);
            }

            if (!$isPresent) {
                $this->addElementToIndex($element, $elasticaIndex, $document);
            }
        }

        if ($isPresent && !$document->shouldIndex($element)) {
            $this->deleteElementFromIndex($element, $elasticaIndex, $document);
        }

        $this->cachesToClear($document);
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
        $doc = $this->documentHelper->elementToDocument($document, $element);
        $index->addDocument($doc);
    }

    /**
     * @param DocumentInterface<AbstractElement> $document
     */
    private function updateElementInIndex(
        AbstractElement $element,
        Index $index,
        DocumentInterface $document,
    ): void {
        $doc = $this->documentHelper->elementToDocument($document, $element);
        // updateDocument() allows partial updates, hence the full replace here
        $index->addDocument($doc);
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

    /**
     * @param DocumentInterface<AbstractElement> $document
     *
     * @return void
     */
    private function cachesToClear(DocumentInterface $document): void
    {
        $tags = $document->getCacheTags();
        $this->documentHelper->clearCaches($tags);
    }
}
