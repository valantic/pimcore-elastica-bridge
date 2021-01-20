<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Model\Element\AbstractElement;
use Pimcore\Model\Listing\AbstractListing;
use Valantic\ElasticaBridgeBundle\Command\Index as IndexCommand;
use Valantic\ElasticaBridgeBundle\DocumentType\AbstractDocument;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

/**
 * Describes how a Pimcore element related to an Elasticsearch in the context of this index.
 * Classes implementing this interface may extend an AbstractDocument and certain methods could be implemented there
 * to result in DRYer code.
 *
 * @see AbstractDocument
 */
interface IndexDocumentInterface extends DocumentInterface
{
    public const META_TYPE = '__type';
    public const META_SUB_TYPE = '__subType';
    public const META_ID = '__id';
    public const ATTRIBUTE_LOCALIZED = 'localized';
    public const ATTRIBUTE_CHILDREN = 'children';
    public const ATTRIBUTE_CHILDREN_RECURSIVE = 'childrenRecursive';
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
}
