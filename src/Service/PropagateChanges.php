<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Elastica\Exception\NotFoundException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class PropagateChanges
{
    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly DocumentHelper $documentHelper,
    ) {
    }

    /**
     * Whenever an event occurs, a decision needs to be made:
     * 1. Which indices might need to be updated?
     * 2. Does the element need to be in Elasticsearch or not?
     * 3. Are there Elasticsearch documents to be created/updated or deleted?
     */
    public function handle(AbstractElement $element): void
    {
        foreach ($this->matchingIndicesForElement($this->indexRepository->flattened(), $element) as $index) {
            $document = $index->findDocumentInstanceByPimcore($element);

            if (!$document instanceof DocumentInterface) {
                continue;
            }

            $this->documentHelper->setTenantIfNeeded($document, $index);

            if (!in_array($document::class, $index->subscribedDocuments(), true)) {
                $this->documentHelper->resetTenantIfNeeded($document, $index);

                continue;
            }

            if ($element->getType() === AbstractObject::OBJECT_TYPE_VARIANT && !$document->treatObjectVariantsAsDocuments()) {
                $this->documentHelper->resetTenantIfNeeded($document, $index);

                continue;
            }

            $elasticsearchId = $document::getElasticsearchId($element);

            $isPresent = $this->isIdInIndex($elasticsearchId, $index);

            if ($document->shouldIndex($element)) {
                if ($isPresent) {
                    $this->updateElementInIndex($element, $index, $document);
                }

                if (!$isPresent) {
                    $this->addElementToIndex($element, $index, $document);
                }
            }

            if (!$document->shouldIndex($element) && $isPresent) {
                $this->deleteElementFromIndex($element, $index, $document);
            }

            $this->documentHelper->resetTenantIfNeeded($document, $index);
        }
    }

    /**
     * @param DocumentInterface<AbstractElement> $document
     */
    private function addElementToIndex(
        AbstractElement $element,
        IndexInterface $index,
        DocumentInterface $document,
    ): void {
        $document = $this->documentHelper->elementToDocument($document, $element);
        $index->getElasticaIndex()->addDocument($document);
    }

    /**
     * @param DocumentInterface<AbstractElement> $document
     */
    private function updateElementInIndex(
        AbstractElement $element,
        IndexInterface $index,
        DocumentInterface $document,
    ): void {
        $document = $this->documentHelper->elementToDocument($document, $element);
        $index->getElasticaIndex()->addDocument($document); // updateDocument() allows partial updates, hence the full replace here
    }

    /**
     * @param DocumentInterface<AbstractElement> $document
     */
    private function deleteElementFromIndex(
        AbstractElement $element,
        IndexInterface $index,
        DocumentInterface $document,
    ): void {
        $elasticsearchId = $document::getElasticsearchId($element);
        $index->getElasticaIndex()->deleteById($elasticsearchId);
    }

    /**
     * Returns an array of indices that could contain $element.
     *
     * @param \Generator<string,IndexInterface,void,void> $indices
     *
     * @return IndexInterface[]
     */
    private function matchingIndicesForElement(\Generator $indices, AbstractElement $element): array
    {
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
    private function isIdInIndex(string $id, IndexInterface $index): bool
    {
        try {
            $index->getElasticaIndex()->getDocument($id);
        } catch (NotFoundException) {
            return false;
        }

        return true;
    }
}
