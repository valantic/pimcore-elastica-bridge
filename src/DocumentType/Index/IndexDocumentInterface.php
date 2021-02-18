<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Model\Element\AbstractElement;
use Pimcore\Model\Listing\AbstractListing;
use Valantic\ElasticaBridgeBundle\Command\Index as IndexCommand;
use Valantic\ElasticaBridgeBundle\DocumentType\AbstractDocument;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

/**
 * Describes how a Pimcore element relates to an Elasticsearch in the context of this index.
 * Classes implementing this interface may extend an AbstractDocument and certain methods could be implemented there
 * to result in DRYer code.
 *
 * @see AbstractDocument
 */
interface IndexDocumentInterface extends DocumentInterface
{
    /**
     * Every Elasticsearch document will contain a __type field, corresponding to DocumentInterface::getType()
     */
    public const META_TYPE = '__type';
    /**
     * Every Elasticsearch document will contain a __subType field, corresponding to DocumentInterface::getSubType()
     */
    public const META_SUB_TYPE = '__subType';
    /**
     * Every Elasticsearch document will contain an __id field, corresponding to DocumentInterface::getPimcoreId()
     */
    public const META_ID = '__id';
    /**
     * The localized attributes generated by DataObjectNormalizerTrait::localizedAttributes() will be added to this field.
     *
     * @see DataObjectNormalizerTrait::localizedAttributes()
     */
    public const ATTRIBUTE_LOCALIZED = 'localized';
    /**
     * The array of children IDs generated by DataObjectNormalizerTrait::children() will be added to this field.
     *
     * @see DataObjectNormalizerTrait::children()
     */
    public const ATTRIBUTE_CHILDREN = 'children';
    /**
     * The array of children IDs generated by DataObjectNormalizerTrait::childrenRecursive() will be added to this field.
     *
     * @see DataObjectNormalizerTrait::childrenRecursive()
     */
    public const ATTRIBUTE_CHILDREN_RECURSIVE = 'childrenRecursive';
    /**
     * The array of related object IDs generated by DocumentNormalizerTrait will be added to this field.
     *
     * @see DocumentNormalizerTrait::$relatedObjects
     */
    public const ATTRIBUTE_RELATED_OBJECTS = 'relatedObjects';

    /**
     * Returns the normalization of the Pimcore element.
     * This is how the Pimcore element will be stored in the Elasticsearch document.
     *
     * @param AbstractElement $element
     *
     * @return array<mixed>
     * @see DocumentNormalizerTrait
     * @see DocumentRelationAwareDataObjectTrait
     * @see DataObjectNormalizerTrait
     */
    public function getNormalized(AbstractElement $element): array;

    /**
     * Indicates whether a Pimcore element should be indexed.
     * E.g. return false when the element is not published.
     *
     * @param AbstractElement $element
     *
     * @return bool
     */
    public function shouldIndex(AbstractElement $element): bool;

    /**
     * Conditions to pass to the listing of Pimcore elements.
     *
     * @return string|null
     * @see IndexCommand
     */
    public function getIndexListingCondition(): ?string; // TODO: refactor to use array of interfaces

    /**
     * @param IndexInterface $index
     *
     * @return AbstractListing
     * @see ListingTrait
     */
    public function getListingInstance(IndexInterface $index): AbstractListing;

    /**
     * Whether Elasticsearch documents should be created for object variants.
     *
     * @return bool
     */
    public function treatObjectVariantsAsDocuments(): bool;
}
