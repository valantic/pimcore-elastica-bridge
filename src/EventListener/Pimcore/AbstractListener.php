<?php

namespace Valantic\ElasticaBridgeBundle\EventListener\Pimcore;

use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
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
    protected ElasticsearchClient $esClient;
    protected DocumentHelper $documentHelper;
    protected IndexHelper $indexHelper;
    protected IndexRepository $indexRepository;

    public function __construct(
        IndexRepository $indexRepository,
        ElasticsearchClient $esClient,
        DocumentHelper $documentHelper,
        IndexHelper $indexHelper
    )
    {
        $this->esClient = $esClient;
        $this->documentHelper = $documentHelper;
        $this->indexHelper = $indexHelper;
        $this->indexRepository = $indexRepository;
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
     *
     * @param AbstractElement $element
     */
    protected function decideAction(AbstractElement $element): void
    {
        foreach ($this->indexHelper->matchingIndicesForElement($this->indexRepository->all(), $element) as $index) {
            $indexDocument = $index->findIndexDocumentInstanceByPimcore($element);

            if (!$indexDocument || !in_array(get_class($indexDocument), $index->subscribedDocuments(), true)) {
                continue;
            }

            if ($element->getType() === AbstractObject::OBJECT_TYPE_VARIANT && !$indexDocument->treatVariantsAsSeparateEntities()) {
                $element = $element->getParent();
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
        }
    }

    protected function ensurePresent(AbstractElement $element): void
    {
        foreach ($this->indexHelper->matchingIndicesForElement($this->indexRepository->all(), $element) as $index) {
            $indexDocument = $index->findIndexDocumentInstanceByPimcore($element);

            if (!$indexDocument || !in_array(get_class($indexDocument), $index->subscribedDocuments(), true) || !$indexDocument->shouldIndex($element)) {
                continue;
            }

            if ($this->indexHelper->isIdInIndex($indexDocument->getElasticsearchId($element), $index)) {
                $this->updateElementInIndex($element, $index, $indexDocument);
                continue;
            }

            $this->addElementToIndex($element, $index, $indexDocument);
        }
    }

    protected function ensureMissing(AbstractElement $element): void
    {
        foreach ($this->indexHelper->matchingIndicesForElement($this->indexRepository->all(), $element) as $index) {
            $indexDocument = $index->findIndexDocumentInstanceByPimcore($element);

            if (!$indexDocument || !in_array(get_class($indexDocument), $index->subscribedDocuments(), true)) {
                continue;
            }

            $elasticsearchId = $indexDocument->getElasticsearchId($element);

            if (!$this->indexHelper->isIdInIndex($elasticsearchId, $index)) {
                continue;
            }

            $index->getElasticaIndex()->deleteById($elasticsearchId);
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
}
