<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\EventListener\Pimcore;

use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Exception\EventListener\PimcoreElementNotFoundException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Service\IndexHelper;

/**
 * An abstract listener for DataObject and Document listeners.
 * These listeners are automatically registered by the bundle and update Elasticsearch with
 * any changes made in Pimcore.
 */
abstract class AbstractListener
{
    protected static bool $isEnabled = true;

    public function __construct(
        protected IndexRepository $indexRepository,
        protected ElasticsearchClient $esClient,
        protected DocumentHelper $documentHelper,
        protected IndexHelper $indexHelper,
    ) {
    }

    public static function enableListener(): void
    {
        self::$isEnabled = true;
    }

    public static function disableListener(): void
    {
        self::$isEnabled = false;
    }

    /**
     * Whenever an event occurs, a decision needs to be made:
     * 1. Which indices might need to be updated?
     * 2. Does the element need to be in Elasticsearch or not?
     * 3. Are there Elasticsearch documents to be created/updated or deleted?
     */
    protected function decideAction(AbstractElement $element): void
    {
        foreach ($this->indexHelper->matchingIndicesForElement($this->indexRepository->flattened(), $element) as $index) {
            $indexDocument = $index->findIndexDocumentInstanceByPimcore($element);

            if (!$indexDocument) {
                continue;
            }

            $this->documentHelper->setTenantIfNeeded($indexDocument, $index);

            if (!in_array($indexDocument::class, $index->subscribedDocuments(), true)) {
                $this->documentHelper->resetTenantIfNeeded($indexDocument, $index);
                continue;
            }

            if ($element->getType() === AbstractObject::OBJECT_TYPE_VARIANT && !$indexDocument->treatObjectVariantsAsDocuments()) {
                $this->documentHelper->resetTenantIfNeeded($indexDocument, $index);
                continue;
            }

            $elasticsearchId = $indexDocument->getElasticsearchId($element);

            $isPresent = $this->indexHelper->isIdInIndex($elasticsearchId, $index);

            if ($indexDocument->shouldIndex($element)) {
                if ($isPresent) {
                    $this->updateElementInIndex($element, $index, $indexDocument);
                }
                if (!$isPresent) {
                    $this->addElementToIndex($element, $index, $indexDocument);
                }
            }

            if (!$indexDocument->shouldIndex($element) && $isPresent) {
                $this->deleteElementFromIndex($element, $index, $indexDocument);
            }

            $this->documentHelper->resetTenantIfNeeded($indexDocument, $index);
        }
    }

    protected function ensurePresent(AbstractElement $element): void
    {
        foreach ($this->indexHelper->matchingIndicesForElement($this->indexRepository->flattened(), $element) as $index) {
            $indexDocument = $index->findIndexDocumentInstanceByPimcore($element);

            if (!$indexDocument) {
                continue;
            }

            $this->documentHelper->setTenantIfNeeded($indexDocument, $index);

            if (!in_array($indexDocument::class, $index->subscribedDocuments(), true) || !$indexDocument->shouldIndex($element)) {
                $this->documentHelper->resetTenantIfNeeded($indexDocument, $index);
                continue;
            }

            if ($this->indexHelper->isIdInIndex($indexDocument->getElasticsearchId($element), $index)) {
                $this->updateElementInIndex($element, $index, $indexDocument);
                $this->documentHelper->resetTenantIfNeeded($indexDocument, $index);
                continue;
            }

            $this->addElementToIndex($element, $index, $indexDocument);
            $this->documentHelper->resetTenantIfNeeded($indexDocument, $index);
        }
    }

    protected function ensureMissing(AbstractElement $element): void
    {
        foreach ($this->indexHelper->matchingIndicesForElement($this->indexRepository->flattened(), $element) as $index) {
            $indexDocument = $index->findIndexDocumentInstanceByPimcore($element);

            if (!$indexDocument) {
                continue;
            }

            $this->documentHelper->setTenantIfNeeded($indexDocument, $index);

            if (!in_array($indexDocument::class, $index->subscribedDocuments(), true)) {
                $this->documentHelper->resetTenantIfNeeded($indexDocument, $index);
                continue;
            }

            $elasticsearchId = $indexDocument->getElasticsearchId($element);

            if (!$this->indexHelper->isIdInIndex($elasticsearchId, $index)) {
                $this->documentHelper->resetTenantIfNeeded($indexDocument, $index);
                continue;
            }

            $index->getElasticaIndex()->deleteById($elasticsearchId);
            $this->documentHelper->resetTenantIfNeeded($indexDocument, $index);
        }
    }

    protected function addElementToIndex(AbstractElement $element, IndexInterface $index, IndexDocumentInterface $indexDocument): void
    {
        $document = $this->documentHelper->elementToIndexDocument($indexDocument, $element);
        $index->getElasticaIndex()->addDocument($document);
    }

    protected function updateElementInIndex(AbstractElement $element, IndexInterface $index, IndexDocumentInterface $indexDocument): void
    {
        $document = $this->documentHelper->elementToIndexDocument($indexDocument, $element);
        $index->getElasticaIndex()->addDocument($document); // updateDocument() allows partial updates, hence the full replace here
    }

    protected function deleteElementFromIndex(AbstractElement $element, IndexInterface $index, IndexDocumentInterface $indexDocument): void
    {
        $elasticsearchId = $indexDocument->getElasticsearchId($element);
        $index->getElasticaIndex()->deleteById($elasticsearchId);
    }

    /**
     * The object passed via the event listener may be the a draft and not the latest published version.
     * This method retrieves the latest published version of that element.
     *
     * @template T of AbstractElement
     *
     * @param T $element
     *
     * @return T
     */
    protected function getFreshElement(AbstractElement $element): AbstractElement
    {
        /** @var class-string<T> $elementClass */
        $elementClass = $element::class;
        $e = new PimcoreElementNotFoundException($element->getId(), $elementClass);

        return $elementClass::getById($element->getId() ?: throw $e) ?: throw $e;
    }
}
