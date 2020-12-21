<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType;

use Pimcore\Model\Document as PimcoreDocument;
use Elastica\Document as ElasticaDocument;
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
        if ($element instanceof PimcoreDocument) {
            return DocumentInterface::TYPE_DOCUMENT . $element->getId();
        }
        throw new RuntimeException('Unknown element type');
    }

    public final function getPimcoreId(ElasticaDocument $document): int
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

    public function getPimcoreElement(ElasticaDocument $document): AbstractElement
    {
        if ($this->getType() === DocumentInterface::TYPE_OBJECT) {
            $element = Concrete::getById($this->getPimcoreId($document));
            if ($element === null) {
                throw new RuntimeException();
            }

            return $element;
        }
        if ($this->getType() === DocumentInterface::TYPE_DOCUMENT) {
            /** @var PimcoreDocument $documentTypeClass */
            $documentTypeClass = PimcoreDocument::class;
            $element = $documentTypeClass::getById($this->getPimcoreId($document));
            if ($element === null) {
                throw new RuntimeException();
            }

            return $element;

        }
        throw new RuntimeException('Unknown element type');
    }
}
