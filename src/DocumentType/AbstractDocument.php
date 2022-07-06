<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\DocumentType;

use Elastica\Document as ElasticaDocument;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Document as PimcoreDocument;
use Pimcore\Model\Document\Listing as DocumentListing;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Exception\DocumentType\ElasticsearchDocumentNotFoundException;
use Valantic\ElasticaBridgeBundle\Exception\DocumentType\PimcoreElementNotFoundException;
use Valantic\ElasticaBridgeBundle\Exception\DocumentType\UnknownPimcoreElementType;

abstract class AbstractDocument implements DocumentInterface
{
    public function getDocumentType(): ?string
    {
        if ($this->getType() !== DocumentInterface::TYPE_DOCUMENT) {
            return null;
        }

        $candidate = [
            PimcoreDocument\Folder::class => 'folder',
            PimcoreDocument\Page::class => 'page',
            PimcoreDocument\Snippet::class => 'snippet',
            PimcoreDocument\Link::class => 'link',
            PimcoreDocument\Hardlink::class => 'hardlink',
            PimcoreDocument\Email::class => 'email',
            PimcoreDocument\Newsletter::class => 'newsletter',
            PimcoreDocument\Printpage::class => 'printpage',
            PimcoreDocument\Printcontainer::class => 'printcontainer',
        ][$this->getSubType()] ?? null;

        if ($candidate === null || !in_array($candidate, PimcoreDocument::getTypes(), true)) {
            throw new UnknownPimcoreElementType($candidate);
        }

        return $candidate;
    }

    final public function getElasticsearchId(AbstractElement $element): string
    {
        if (in_array($element->getType(), DocumentInterface::TYPES, true)) {
            return $element->getType() . $element->getId();
        }

        if ($element instanceof PimcoreDocument) {
            return DocumentInterface::TYPE_DOCUMENT . $element->getId();
        }

        throw new UnknownPimcoreElementType($element->getType());
    }

    final public function getPimcoreId(ElasticaDocument $document): int
    {
        if ($document->getId() === null) {
            throw new ElasticsearchDocumentNotFoundException($document->getId());
        }

        return (int) str_replace(DocumentInterface::TYPES, '', $document->getId());
    }

    public function getListingClass(): string
    {
        if (in_array($this->getType(), [DocumentInterface::TYPE_OBJECT, DocumentInterface::TYPE_VARIANT], true)) {
            return $this->getSubType() . '\Listing';
        }

        if ($this->getType() === DocumentInterface::TYPE_DOCUMENT) {
            // TODO: this listing doesn't seem to have an option to e.g. only list Hardlinks
            return DocumentListing::class;
        }

        throw new UnknownPimcoreElementType($this->getType());
    }

    public function getPimcoreElement(ElasticaDocument $document): AbstractElement
    {
        if (in_array($this->getType(), [DocumentInterface::TYPE_OBJECT, DocumentInterface::TYPE_VARIANT], true)) {
            $pimcoreId = $this->getPimcoreId($document);
            $element = Concrete::getById($pimcoreId);

            if ($element === null) {
                throw new PimcoreElementNotFoundException($pimcoreId);
            }

            return $element;
        }

        if ($this->getType() === DocumentInterface::TYPE_DOCUMENT) {
            /** @var PimcoreDocument $documentTypeClass */
            $documentTypeClass = $this->getSubType();
            $pimcoreId = $this->getPimcoreId($document);
            $element = $documentTypeClass::getById($pimcoreId);

            if ($element === null) {
                throw new PimcoreElementNotFoundException($pimcoreId);
            }

            return $element;
        }

        throw new UnknownPimcoreElementType($this->getType());
    }
}
