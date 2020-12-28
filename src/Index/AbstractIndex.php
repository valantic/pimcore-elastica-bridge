<?php

namespace Valantic\ElasticaBridgeBundle\Index;

use Elastica\Document;
use Elastica\Index;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\Match;
use Elastica\ResultSet;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;

abstract class AbstractIndex implements IndexInterface
{
    protected bool $areGlobalFiltersEnabled = true;
    protected ElasticsearchClient $client;

    public function __construct(ElasticsearchClient $client)
    {
        $this->client = $client;
    }

    public function getGlobalFilters(): array
    {
        return [];
    }

    public function getMapping(): array
    {
        return [];
    }

    public function getSettings(): array
    {
        return [];
    }

    public function disableGlobalFilters(): void
    {
        $this->areGlobalFiltersEnabled = false;
    }

    public function enableGlobalFilters(): void
    {
        $this->areGlobalFiltersEnabled = true;
    }

    public final function hasMapping(): bool
    {
        return count($this->getMapping()) > 0;
    }

    public final function getCreateArguments(): array
    {
        return array_filter([
            'mappings' => $this->getMapping(),
            'settings' => $this->getSettings(),
        ]);
    }

    public function getBatchSize(): int
    {
        return 5000;
    }

    public function getElasticaIndex(): Index
    {
        return $this->client->getIndex($this->getName());
    }

    public function isElementAllowedInIndex(AbstractElement $element): bool
    {
        return $this->findIndexDocumentInstanceByPimcore($element) !== null;
    }

    public function getIndexDocumentInstance(Document $document): ?IndexDocumentInterface
    {
        $type = $document->get(IndexDocumentInterface::META_TYPE);
        $subType = $document->get(IndexDocumentInterface::META_SUB_TYPE);
        foreach ($this->getAllowedDocuments() as $allowedDocument) {
            /** @var IndexDocumentInterface $documentInstance */
            $documentInstance = new $allowedDocument();
            if ($documentInstance->getType() === $type && $documentInstance->getSubType() === $subType) {
                return $documentInstance;
            }
        }

        return null;
    }

    public function getDocumentFromElement(AbstractElement $element): ?Document
    {
        $documentInstance = $this->findIndexDocumentInstanceByPimcore($element);
        if (!$documentInstance) {
            return null;
        }

        $query = (new BoolQuery())
            ->addMust(new Match(IndexDocumentInterface::META_ID, $element->getId()))
            ->addMust(new Match(IndexDocumentInterface::META_TYPE, $documentInstance->getType()))
            ->addMust(new Match(IndexDocumentInterface::META_SUB_TYPE, $documentInstance->getSubType()));

        $search = $this->getElasticaIndex()->search(new Query($query));

        if ($search->count() !== 1) {
            return null;
        }

        return $search->getDocuments()[0];
    }

    public function findIndexDocumentInstanceByPimcore(AbstractElement $element): ?IndexDocumentInterface
    {
        foreach ($this->getAllowedDocuments() as $allowedDocument) {
            /** @var IndexDocumentInterface $documentInstance */
            $documentInstance = new $allowedDocument();
            if (in_array($documentInstance->getType(), [
                    DocumentInterface::TYPE_OBJECT,
                    DocumentInterface::TYPE_DOCUMENT,
                ], true) && $documentInstance->getSubType() === get_class($element)) {
                return $documentInstance;
            }
        }

        return null;
    }

    /**
     * @param Query\AbstractQuery $query
     *
     * @return AbstractElement[]
     */
    public function searchForElements(Query\AbstractQuery $query): array
    {
        return $this->documentResultToElements($this->getElasticaIndex()->search($query));
    }

    /**
     * @param ResultSet $result
     *
     * @return AbstractElement[]
     */
    public function documentResultToElements(ResultSet $result): array
    {
        $elements = [];
        foreach ($result->getDocuments() as $esDoc) {
            $instance = $this->getIndexDocumentInstance($esDoc);
            if (!$instance) {
                continue;
            }
            $elements[] = $instance->getPimcoreElement($esDoc);
        }

        return $elements;
    }

    public function subscribedDocuments(): array
    {
        return $this->getAllowedDocuments();
    }
}
