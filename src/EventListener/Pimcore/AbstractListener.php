<?php

namespace Valantic\ElasticaBridgeBundle\EventListener\Pimcore;

use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Service\BridgeHelper;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Service\IndexHelper;

abstract class AbstractListener
{
    protected static bool $isEnabled = true;
    /**
     * @var IndexInterface[]
     */
    protected array $indices;
    /**
     * @var DocumentInterface[]
     */
    protected array $documents;
    /**
     * @var IndexDocumentInterface[]
     */
    protected array $indexDocuments;
    protected ElasticsearchClient $esClient;
    protected BridgeHelper $bridgeHelper;
    protected DocumentHelper $documentHelper;
    protected IndexHelper $indexHelper;

    /**
     * Index constructor.
     *
     * @param iterable<IndexInterface> $indices
     * @param iterable<DocumentInterface> $documents
     * @param iterable<IndexDocumentInterface> $indexDocuments
     * @param ElasticsearchClient $esClient
     * @param BridgeHelper $bridgeHelper
     * @param DocumentHelper $documentHelper
     * @param IndexHelper $indexHelper
     */
    public function __construct(
        iterable $indices,
        iterable $documents,
        iterable $indexDocuments,
        ElasticsearchClient $esClient,
        BridgeHelper $bridgeHelper,
        DocumentHelper $documentHelper,
        IndexHelper $indexHelper
    )
    {
        $this->bridgeHelper = $bridgeHelper;
        $this->indices = $this->bridgeHelper->iterableToArray($indices);
        $this->documents = $this->bridgeHelper->iterableToArray($documents);
        $this->indexDocuments = $this->bridgeHelper->iterableToArray($indexDocuments);
        $this->esClient = $esClient;
        $this->documentHelper = $documentHelper;
        $this->indexHelper = $indexHelper;
    }

    public static function enableListener(): void
    {
        self::$isEnabled = true;
    }

    public static function disableListener(): void
    {
        self::$isEnabled = false;
    }

    protected function decideAction(AbstractElement $element): void
    {
        foreach ($this->indexHelper->matchingIndicesForElement($this->indices, $element) as $index) {
            $indexDocument = $index->findIndexDocumentInstanceByPimcore($element);

            if (!$indexDocument || !in_array(get_class($indexDocument), $index->subscribedDocuments(), true)) {
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
        }
    }

    protected function ensurePresent(AbstractElement $element): void
    {
        foreach ($this->indexHelper->matchingIndicesForElement($this->indices, $element) as $index) {
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
        foreach ($this->indexHelper->matchingIndicesForElement($this->indices, $element) as $index) {
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
