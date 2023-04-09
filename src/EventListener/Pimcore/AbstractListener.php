<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\EventListener\Pimcore;

use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
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
abstract class AbstractListener implements EventSubscriberInterface
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

            $isPresent = $this->indexHelper->isIdInIndex($elasticsearchId, $index);

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

    protected function ensurePresent(AbstractElement $element): void
    {
        foreach ($this->indexHelper->matchingIndicesForElement($this->indexRepository->flattened(), $element) as $index) {
            $document = $index->findDocumentInstanceByPimcore($element);

            if (!$document instanceof DocumentInterface) {
                continue;
            }

            $this->documentHelper->setTenantIfNeeded($document, $index);

            if (!in_array($document::class, $index->subscribedDocuments(), true) || !$document->shouldIndex($element)) {
                $this->documentHelper->resetTenantIfNeeded($document, $index);

                continue;
            }

            if ($this->indexHelper->isIdInIndex($document::getElasticsearchId($element), $index)) {
                $this->updateElementInIndex($element, $index, $document);
                $this->documentHelper->resetTenantIfNeeded($document, $index);

                continue;
            }

            $this->addElementToIndex($element, $index, $document);
            $this->documentHelper->resetTenantIfNeeded($document, $index);
        }
    }

    protected function ensureMissing(AbstractElement $element): void
    {
        foreach ($this->indexHelper->matchingIndicesForElement($this->indexRepository->flattened(), $element) as $index) {
            $document = $index->findDocumentInstanceByPimcore($element);

            if (!$document instanceof DocumentInterface) {
                continue;
            }

            $this->documentHelper->setTenantIfNeeded($document, $index);

            if (!in_array($document::class, $index->subscribedDocuments(), true)) {
                $this->documentHelper->resetTenantIfNeeded($document, $index);

                continue;
            }

            $elasticsearchId = $document::getElasticsearchId($element);

            if (!$this->indexHelper->isIdInIndex($elasticsearchId, $index)) {
                $this->documentHelper->resetTenantIfNeeded($document, $index);

                continue;
            }

            $index->getElasticaIndex()->deleteById($elasticsearchId);
            $this->documentHelper->resetTenantIfNeeded($document, $index);
        }
    }

    protected function addElementToIndex(AbstractElement $element, IndexInterface $index, DocumentInterface $document): void
    {
        $document = $this->documentHelper->elementToDocument($document, $element);
        $index->getElasticaIndex()->addDocument($document);
    }

    protected function updateElementInIndex(AbstractElement $element, IndexInterface $index, DocumentInterface $document): void
    {
        $document = $this->documentHelper->elementToDocument($document, $element);
        $index->getElasticaIndex()->addDocument($document); // updateDocument() allows partial updates, hence the full replace here
    }

    protected function deleteElementFromIndex(AbstractElement $element, IndexInterface $index, DocumentInterface $document): void
    {
        $elasticsearchId = $document::getElasticsearchId($element);
        $index->getElasticaIndex()->deleteById($elasticsearchId);
    }

    /**
     * The object passed via the event listener may be a draft and not the latest published version.
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

        if ($element->getId() === null) {
            throw $e;
        }

        return $elementClass::getById($element->getId()) ?? throw $e;
    }
}
