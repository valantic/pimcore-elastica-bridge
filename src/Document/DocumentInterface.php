<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Listing;
use Pimcore\Model\Document as PimcoreDocument;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Index\DocumentContext;
use Valantic\ElasticaBridgeBundle\Index\IndexContext;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

/**
 * Describes how a Pimcore element relates to an Elasticsearch index.
 *
 * @template TElement of AbstractElement
 */
interface DocumentInterface
{
    public const META_TYPE = '__type';
    public const META_SUB_TYPE = '__subType';
    public const META_ID = '__id';
    public const META_TENANT = '__tenant';
    public const META_LANGUAGE = '__language';
    public const META_COUNTRY = '__country';
    public const ATTRIBUTE_LOCALIZED = 'localized';
    public const ATTRIBUTE_CHILDREN = 'children';
    public const ATTRIBUTE_CHILDREN_RECURSIVE = 'childrenRecursive';
    public const ATTRIBUTE_RELATED_OBJECTS = 'relatedObjects';

    /**
     * Returns the Elasticsearch ID for a Pimcore element.
     * Used in single-context (legacy) mode. For multi-context mode, getIdForContext() is used.
     *
     * @param TElement $element
     *
     * @internal
     */
    public static function getElasticsearchId(AbstractElement $element): string;

    /**
     * Returns the Elasticsearch document ID for a specific DocumentContext.
     * Default: getElasticsearchId() with non-null context fields appended.
     *
     * @param TElement $element
     *
     * @internal
     */
    public static function getIdForContext(AbstractElement $element, DocumentContext $documentContext): string;

    /**
     * Defines the Pimcore type of this document.
     */
    public function getType(): DocumentType;

    /**
     * The subtype, e.g. the DataObject class or Document\Page.
     * Returning null will result in all elements of getType() being included.
     *
     * @return ?class-string<AbstractElement>
     */
    public function getSubType(): ?string;

    /**
     * Returns the normalization of the Pimcore element.
     * Used in single-context (legacy) mode. For multi-context mode, getNormalizedForContext() is used.
     *
     * @param TElement $element
     *
     * @return array<mixed>
     */
    public function getNormalized(AbstractElement $element): array;

    /**
     * Returns the normalization for a specific IndexContext and DocumentContext.
     * Default: delegates to getNormalized().
     *
     * @param TElement $element
     *
     * @return array<mixed>
     */
    public function getNormalizedForContext(AbstractElement $element, IndexContext $indexContext, DocumentContext $documentContext): array;

    /**
     * Returns the DocumentContexts for which this element should be indexed within the given IndexContext.
     * An empty array means the element should not be indexed in this context.
     * Default: delegates to shouldIndex() and returns a single default DocumentContext.
     *
     * @param TElement $element
     *
     * @return DocumentContext[]
     */
    public function getDocumentContexts(AbstractElement $element, IndexContext $indexContext): array;

    /**
     * Indicates whether a Pimcore element should be indexed.
     * Used in single-context (legacy) mode. For multi-context mode, getDocumentContexts() is used.
     *
     * @param TElement $element
     */
    public function shouldIndex(AbstractElement $element): bool;

    /**
     * @see ListingTrait
     */
    public function getListingInstance(IndexInterface $index): Listing|PimcoreDocument\Listing|Asset\Listing;

    /**
     * Whether Elasticsearch documents should be created for object variants.
     */
    public function treatObjectVariantsAsDocuments(): bool;
}
