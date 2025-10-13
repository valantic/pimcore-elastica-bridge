<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Index;

use Elastica\Index;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Command\Index as IndexCommand;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Document\DocumentNormalizerTrait;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;

interface IndexInterface
{
    /**
     * The name of the Elasticsearch index.
     */
    public function getName(): string;

    /**
     * The number of Pimcore elements to be stored in the index in one batch.
     * This is used e.g. when populating the index.
     *
     * @see IndexCommand
     */
    public function getBatchSize(): int;

    /**
     * Defines if the the index should be populated in subprocesses.
     * This is useful for large indexes to avoid memory issues.
     */
    public function shouldPopulateInSubprocesses(): bool;

    /**
     * Defines the mapping to be used for this index.
     * Passed 1:1 to Elasticsearch.
     *
     * @return array{properties:array<string,mixed>}
     */
    public function getMapping(): array;

    /**
     * Defines the settings to be used for this index.
     * Passed 1:1 to Elasticsearch.
     *
     * @return array<array<mixed>>
     */
    public function getSettings(): array;

    /**
     * @return array<string,array<mixed>>
     *
     * @internal
     */
    public function getCreateArguments(): array;

    /**
     * Defines the types of documents found in this index. Array of classes implementing DocumentInterface.
     *
     * @return class-string[] Class names of DocumentInterface classes
     *
     * @see DocumentInterface
     */
    public function getAllowedDocuments(): array;

    /**
     * The documents this index subscribes to i.e. the documents which are updated using event listeners.
     *
     * @return class-string[] Class names of DocumentInterface instances
     *
     * @see DocumentInterface
     */
    public function subscribedDocuments(): array;

    /**
     * Checks whether a given Pimcore element is allowed in this index.
     *
     * @internal
     */
    public function isElementAllowedInIndex(AbstractElement $element): bool;

    /**
     * Exposes a pre-configured Elastica client for this index.
     */
    public function getElasticaIndex(): Index;

    /**
     * Given a Pimcore element, returns the corresponding DocumentInterface.
     *
     * @return DocumentInterface<AbstractElement>|null
     */
    public function findDocumentInstanceByPimcore(AbstractElement $element): ?DocumentInterface;

    /**
     * When indexing DataObjects based on usage in Pimcore Documents, the index is queried during indexing.
     * In these instances, the index needs to be refreshed in order for newly-added data to be available immediately.
     *
     * While populating is happening (as indicated by IndexCommand::$isPopulating), use the inactive index.
     *
     * @see DocumentNormalizerTrait::$relatedObjects
     * @see IndexCommand
     * @see IndexCommand::$isPopulating
     * @see IndexInterface::getBlueGreenInactiveElasticaIndex()
     */
    public function refreshIndexAfterEveryDocumentWhenPopulating(): bool;

    /**
     * Indicates whether this index uses a blue-green setup to ensure re-populating the index doesn't result
     * in a loss of functionality.
     *
     * @see IndexCommand
     */
    public function usesBlueGreenIndices(): bool;

    /**
     * Checks whether the blue and green indices are correctly set up.
     *
     * @internal
     */
    public function hasBlueGreenIndices(): bool;

    /**
     * Returns the currently active blue/green suffix.
     *
     * @throws BlueGreenIndicesIncorrectlySetupException
     *
     * @internal
     */
    public function getBlueGreenActiveSuffix(): IndexBlueGreenSuffix;

    /**
     * Returns the currently inactive blue/green suffix.
     *
     * @throws BlueGreenIndicesIncorrectlySetupException
     *
     * @internal
     */
    public function getBlueGreenInactiveSuffix(): IndexBlueGreenSuffix;

    /**
     * Returns the currently active blue/green Elastica index.
     *
     * @throws BlueGreenIndicesIncorrectlySetupException
     *
     * @see IndexInterface::getElasticaIndex()
     *
     * @internal
     */
    public function getBlueGreenActiveElasticaIndex(): Index;

    /**
     * Returns the currently inactive blue/green Elastica index.
     *
     * @throws BlueGreenIndicesIncorrectlySetupException
     *
     * @see IndexInterface::getElasticaIndex()
     *
     * @internal
     */
    public function getBlueGreenInactiveElasticaIndex(): Index;
}
