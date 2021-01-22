<?php

namespace Valantic\ElasticaBridgeBundle\Index;

use Elastica\Document;
use Elastica\Index;
use Elastica\Query;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Command\Index as IndexCommand;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\DocumentNormalizerTrait;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;

interface IndexInterface
{
    /**
     * The suffix for the blue index
     */
    public const INDEX_SUFFIX_BLUE = '--blue';
    /**
     * The suffix for the green index
     */
    public const INDEX_SUFFIX_GREEN = '--green';
    /**
     * List of valid index suffixes
     */
    public const INDEX_SUFFIXES = [self::INDEX_SUFFIX_BLUE, self::INDEX_SUFFIX_GREEN];

    /**
     * The name of the Elasticsearch index
     *
     * @return string
     */
    public function getName(): string;

    /**
     * The number of Pimcore elements to be stored in the index in one batch.
     * This is used e.g. when populating the index.
     *
     * @return int
     * @see IndexCommand
     *
     */
    public function getBatchSize(): int;

    /**
     * These filter queries will be applied to every query made to this index.
     *
     * @return Query[]
     */
    public function getGlobalFilters(): array;

    /**
     * Disables global filters.
     */
    public function disableGlobalFilters(): void;

    /**
     * Enables global filters.
     */
    public function enableGlobalFilters(): void;

    /**
     * @return bool
     * @internal
     */
    public function hasMapping(): bool;

    /**
     * Defines the mapping to be used for this index.
     * Passed 1:1 to Elasticsearch.
     *
     * @return array<array>
     */
    public function getMapping(): array;

    /**
     * Defines the settings to be used for this index.
     * Passed 1:1 to Elasticsearch.
     *
     * @return array<array>
     */
    public function getSettings(): array;

    /**
     * @return array<array>
     * @internal
     */
    public function getCreateArguments(): array;

    /**
     * Defines the types of documents found in this index. Array of classes implementing IndexDocumentInterface.
     *
     * @return string[] Class names of IndexDocumentInterface classes
     * @see IndexDocumentInterface
     */
    public function getAllowedDocuments(): array;

    /**
     * The documents this index subscribes to i.e. the documents which are updated using event listeners.
     *
     * @return string[] Class names of IndexDocumentInterface instances
     * @see IndexDocumentInterface
     */
    public function subscribedDocuments(): array;

    /**
     * Checks whether a given Pimcore element is allowed in this index.
     *
     * @param AbstractElement $element
     *
     * @return bool
     * @internal
     */
    public function isElementAllowedInIndex(AbstractElement $element): bool;

    /**
     * Given an Elasticsearch document, returns the associated IndexDocumentInterface.
     *
     * @param Document $document
     *
     * @return IndexDocumentInterface|null
     * @internal
     */
    public function getIndexDocumentInstance(Document $document): ?IndexDocumentInterface;

    /**
     * Exposes a pre-configured Elastica client for this index.
     *
     * @return Index
     */
    public function getElasticaIndex(): Index;

    /**
     * Given a Pimcore element, returns the corresponding Elasticsearch document (if available).
     *
     * @param AbstractElement $element
     *
     * @return Document|null
     */
    public function getDocumentFromElement(AbstractElement $element): ?Document;

    /**
     * Given a Pimcore element, returns the corresponding IndexDocumentInterface.
     *
     * @param AbstractElement $element
     *
     * @return IndexDocumentInterface|null
     */
    public function findIndexDocumentInstanceByPimcore(AbstractElement $element): ?IndexDocumentInterface;

    /**
     * When indexing DataObjects based on usage in Pimcore Documents, the index is queried during indexing.
     * In these instances, the index needs to be refreshed in order for newly-added data to be available immediately.
     *
     * @return bool
     * @see DocumentNormalizerTrait::$relatedObjects
     * @see IndexCommand
     */
    public function refreshIndexAfterEveryIndexDocumentWhenPopulating(): bool;

    /**
     * Indicates whether this index uses a blue-green setup to ensure re-populating the index doesn't result
     * in a loss of functionality.
     *
     * @return bool
     * @see IndexCommand
     */
    public function usesBlueGreenIndices(): bool;

    /**
     * Checks whether the blue and green indices are correctly set up.
     *
     * @return bool
     * @internal
     */
    public function hasBlueGreenIndices(): bool;

    /**
     * Returns the currently active blue/green suffix.
     *
     * @return string
     * @throws BlueGreenIndicesIncorrectlySetupException
     * @internal
     */
    public function getBlueGreenActiveSuffix(): string;

    /**
     * Returns the currently inactive blue/green suffix.
     *
     * @return string
     * @throws BlueGreenIndicesIncorrectlySetupException
     * @internal
     */
    public function getBlueGreenInactiveSuffix(): string;

    /**
     * Returns the currently active blue/green Elastica index.
     *
     * @return Index
     * @throws BlueGreenIndicesIncorrectlySetupException
     * @see IndexInterface::getElasticaIndex()
     * @internal
     */
    public function getBlueGreenActiveElasticaIndex(): Index;

    /**
     * Returns the currently inactive blue/green Elastica index.
     *
     * @return Index
     * @throws BlueGreenIndicesIncorrectlySetupException
     * @see IndexInterface::getElasticaIndex()
     * @internal
     */
    public function getBlueGreenInactiveElasticaIndex(): Index;
}
