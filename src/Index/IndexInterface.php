<?php

namespace Valantic\ElasticaBridgeBundle\Index;

use Elastica\Document;
use Elastica\Index;
use Elastica\Query;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;

interface IndexInterface
{
    public function getName(): string;

    public function getBatchSize(): int;

    /**
     * @return Query[]
     */
    public function getGlobalFilters(): array;

    public function disableGlobalFilters(): void;

    public function enableGlobalFilters(): void;

    public function hasMapping(): bool;

    /**
     * @return array<array>
     */
    public function getMapping(): array;

    /**
     * @return array<array>
     */
    public function getSettings(): array;

    /**
     * @return array<array>
     */
    public function getCreateArguments(): array;

    /**
     * @return string[] Class names of DocumentIndexInterface instances
     */
    public function getAllowedDocuments(): array;

    /**
     * @return string[] Class names of DocumentIndexInterface instances
     */
    public function subscribedDocuments(): array;

    public function isElementAllowedInIndex(AbstractElement $element): bool;

    public function getIndexDocumentInstance(Document $document): ?IndexDocumentInterface;

    public function getElasticaIndex(): Index;

    public function getDocumentFromElement(AbstractElement $element): ?Document;

    public function findIndexDocumentInstanceByPimcore(AbstractElement $element): ?IndexDocumentInterface;

    public function refreshIndexAfterEveryIndexDocumentWhenPopulating(): bool;
}
