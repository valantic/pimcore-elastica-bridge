<?php

namespace Valantic\ElasticaBridgeBundle\Index;

use Elastica\Document;
use Elastica\Index;
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
        if (!$this->hasMapping()) {
            return [];
        }

        return ['mappings' => $this->getMapping()];
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
        foreach ($this->getAllowedDocuments() as $allowedDocument) {
            /** @var IndexDocumentInterface $documentInstance */
            $documentInstance = new $allowedDocument();
            if (in_array($documentInstance->getType(), [
                    DocumentInterface::TYPE_OBJECT,
                    DocumentInterface::TYPE_DOCUMENT,
                ], true) && $documentInstance->getSubType() === get_class($element)) {
                return true;
            }
        }

        return false;
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
}
