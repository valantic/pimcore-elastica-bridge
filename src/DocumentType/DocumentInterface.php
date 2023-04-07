<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\DocumentType;

use Elastica\Document;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Command\Index as IndexCommand;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;

/**
 * This interface describes a Pimcore element in the context of Elasticsearch.
 * Classes implementing this interface can be re-used in multiple indices.
 * Oftentimes, implementing AbstractDocument is much simpler.
 *
 * @see AbstractDocument
 */
interface DocumentInterface
{
    /**
     * Defines the Pimcore type of this document.
     */
    public function getType(): DocumentType;

    /**
     * The subtype, e.g. the DataObject class or Document\Page.
     *
     * @return class-string
     */
    public function getSubType(): string;

    /**
     * @return string|null
     *
     * @internal
     */
    public function getDocumentType(): ?string;

    /**
     * Returns the Elasticsearch ID for a Pimcore element.
     *
     * @param AbstractElement $element
     *
     * @return string
     *
     * @internal
     */
    public function getElasticsearchId(AbstractElement $element): string;

    /**
     * The name of the class to use for listing all the associated Pimcore elements.
     *
     * @return string
     *
     * @see IndexCommand
     *
     * @internal
     */
    public function getListingClass(): string;
}
