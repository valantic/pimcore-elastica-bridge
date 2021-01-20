<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType;

use Elastica\Document;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Command\Index as IndexCommand;

/**
 * This interface describes a Pimcore element in the context of Elasticsearch.
 * Classes implementing this interface can be re-used in multiple indices.
 * Oftentimes, implementing AbstractDocument is much simpler.
 *
 * @see AbstractDocument
 */
interface DocumentInterface
{
    public const TYPE_ASSET = 'asset';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_OBJECT = 'object';
    public const TYPES = [self::TYPE_ASSET, self::TYPE_DOCUMENT, self::TYPE_OBJECT];

    /**
     * Defines the Pimcore type of this document. One of self::TYPES.
     *
     * @return string
     */
    public function getType(): string;

    /**
     * The subtype, e.g. the DataObject class or Document\Page.
     *
     * @return string
     */
    public function getSubType(): string;

    /**
     * @return string|null
     * @internal
     */
    public function getDocumentType(): ?string;

    /**
     * Returns the Elasticsearch ID for a Pimcore element.
     *
     * @param AbstractElement $element
     *
     * @return string
     * @internal
     */
    public function getElasticsearchId(AbstractElement $element): string;

    /**
     * Returns the Pimcore ID for an Elasticsearch document.
     *
     * @param Document $document
     *
     * @return int
     * @internal
     */
    public function getPimcoreId(Document $document): int;

    /**
     * The name of the class to use for listing all the associated Pimcore elements.
     *
     * @return string
     * @see IndexCommand
     * @internal
     */
    public function getListingClass(): string;

    /**
     * Given an Elasticsearch document, return the corresponding Pimcore element.
     * This method can be overridden to use the correct return type for that instance.
     *
     * @param Document $document
     *
     * @return AbstractElement
     */
    public function getPimcoreElement(Document $document): AbstractElement;
}
