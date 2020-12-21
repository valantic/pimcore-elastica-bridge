<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Model\Element\AbstractElement;
use Pimcore\Model\Listing\AbstractListing;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

interface IndexDocumentInterface extends DocumentInterface
{
    public const META_TYPE = '__type';
    public const META_SUB_TYPE = '__subType';
    public const META_ID = '__id';
    public const ATTRIBUTE_LOCALIZED = 'localized';
    public const ATTRIBUTE_CHILDREN = 'children';
    public const ATTRIBUTE_CHILDREN_RECURSIVE = 'childrenRecursive';

    /**
     * @return array<mixed>
     */
    public function getNormalized(AbstractElement $element): array;

    public function shouldIndex(AbstractElement $element): bool;

    public function getIndexListingCondition(): ?string; // TODO: refactor to use array of interfaces

    public function getListingInstance(IndexInterface $index): AbstractListing;
}
