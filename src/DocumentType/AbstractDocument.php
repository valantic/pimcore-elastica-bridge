<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\DocumentType;

use Pimcore\Model\Asset;
use Pimcore\Model\Document as PimcoreDocument;
use Pimcore\Model\Document\Listing as DocumentListing;
use Pimcore\Model\Asset\Listing as AssetListing;
use Pimcore\Model\Element\AbstractElement;
use UnhandledMatchError;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Exception\DocumentType\UnknownPimcoreElementType;

abstract class AbstractDocument implements DocumentInterface
{
    public function getDocumentType(): ?string
    {
        if (!in_array($this->getType(), DocumentType::casesSubTypeListing(), true)) {
            return null;
        }

        $candidate = null;

        if ($this->getType() === DocumentType::DOCUMENT) {
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

            if (!in_array($candidate, PimcoreDocument::getTypes(), true)) {
                throw new UnknownPimcoreElementType($candidate);
            }
        }

        if ($this->getType() === DocumentType::ASSET) {
            $candidate = [
                Asset\Archive::class => 'archive',
                Asset\Audio::class => 'audio',
                Asset\Document::class => 'document',
                Asset\Folder::class => 'folder',
                Asset\Image::class => 'image',
                Asset\Text::class => 'text',
                Asset\Unknown::class => 'unknown',
                Asset\Video::class => 'video',
            ][$this->getSubType()] ?? null;

            if (!in_array($candidate, Asset::getTypes(), true)) {
                throw new UnknownPimcoreElementType($candidate);
            }
        }

        if ($candidate === null) {
            throw new UnknownPimcoreElementType($candidate);
        }

        return $candidate;
    }

    final public function getElasticsearchId(AbstractElement $element): string
    {
        $documentType = DocumentType::tryFrom($element->getType());

        if ($element instanceof Asset) {
            return DocumentType::ASSET->value . $element->getId();
        }

        if ($element instanceof PimcoreDocument) {
            return DocumentType::DOCUMENT->value . $element->getId();
        }

        if (in_array($documentType, DocumentType::casesDataObjects(), true)) {
            return $documentType->value . $element->getId();
        }

        throw new UnknownPimcoreElementType($documentType?->value);
    }

    /**
     * @return class-string
     */
    public function getListingClass(): string
    {
        try {
            return match ($this->getType()) {
                DocumentType::ASSET => AssetListing::class,
                DocumentType::DOCUMENT => DocumentListing::class,
                DocumentType::DATA_OBJECT, DocumentType::VARIANT => $this->getSubType() . '\Listing',
            };
        } catch (UnhandledMatchError) {
            throw new UnknownPimcoreElementType($this->getType()->value);
        }
    }
}
