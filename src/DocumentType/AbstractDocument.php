<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType;

use Elastica\Document;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Document\Listing as DocumentListing;
use Pimcore\Model\Element\AbstractElement;
use RuntimeException;

abstract class AbstractDocument implements DocumentInterface
{
    public final function getElasticsearchId(AbstractElement $element): string
    {
        if (in_array($element->getType(), DocumentInterface::TYPES, true)) {
            return $element->getType() . $element->getId();
        }
        throw new RuntimeException('Unknown element type');
    }

    public final function getPimcoreId(Document $document): int
    {
        if ($document->getId() === null) {
            throw new RuntimeException();
        }

        return (int)str_replace(DocumentInterface::TYPES, '', $document->getId());
    }

    public function getListingClass(): string
    {
        if ($this->getType() === DocumentInterface::TYPE_OBJECT) {
            return $this->getSubType() . '\Listing';
        }
        if ($this->getType() === DocumentInterface::TYPE_DOCUMENT) {
            // TODO: this listing doesn't seem to have an option to e.g. only list Hardlinks
            return DocumentListing::class;
        }
        throw new RuntimeException('Unknown element type');
    }

    public function getPimcoreElement(Document $document): AbstractElement
    {
        if ($this->getType() === DocumentInterface::TYPE_OBJECT) {
            $element = Concrete::getById($this->getPimcoreId($document));
            if ($element === null) {
                throw new RuntimeException();
            }

            return $element;
        }
        if ($this->getType() === DocumentInterface::TYPE_DOCUMENT) {
            /** @var \Pimcore\Model\Document $documentTypeClass */
            $documentTypeClass = $this->getSubType();
            $element = $documentTypeClass::getById($this->getPimcoreId($document));
            if ($element === null) {
                throw new RuntimeException();
            }

            return $element;

        }
        throw new RuntimeException('Unknown element type');
    }
}
