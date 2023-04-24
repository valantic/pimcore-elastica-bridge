<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Pimcore\Model\DataObject;
use Pimcore\Model\Listing\AbstractListing;
use Valantic\ElasticaBridgeBundle\Exception\DocumentType\PimcoreListingClassNotFoundException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Listing as DataObjectListing;
use Pimcore\Model\Document as PimcoreDocument;
use Pimcore\Model\Document\Listing as DocumentListing;
use Pimcore\Model\Asset\Listing as AssetListing;
use Pimcore\Model\Element\AbstractElement;
use UnhandledMatchError;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Exception\DocumentType\UnknownPimcoreElementType;

/**
 * @template TElement of AbstractElement
 *
 * @implements DocumentInterface<TElement>
 */
abstract class AbstractDocument implements DocumentInterface
{
    public function treatObjectVariantsAsDocuments(): bool
    {
        return false;
    }

    public function getListingInstance(IndexInterface $index): AbstractListing
    {
        /** @var class-string<AbstractListing> $listingClass */
        $listingClass = $this->getListingClass();

        /** @var AbstractListing $listingInstance */
        $listingInstance = new $listingClass();

        if ($this->getIndexListingCondition() !== null) {
            $listingInstance->setCondition($this->getIndexListingCondition());
        }

        if (in_array($this->getType(), DocumentType::casesPublishedState(), true)) {
            /** @var DocumentListing|DataObjectListing $listingInstance */
            $listingInstance->setUnpublished($this->includeUnpublishedElementsInListing());
        }

        if ($this->getType() === DocumentType::DATA_OBJECT && $this->treatObjectVariantsAsDocuments()) {
            /** @var DataObjectListing $listingInstance */
            $listingInstance->setObjectTypes([
                DataObject\AbstractObject::OBJECT_TYPE_OBJECT,
                DataObject\AbstractObject::OBJECT_TYPE_VARIANT,
            ]);
        }

        if (in_array($this->getType(), DocumentType::casesSubTypeListing(), true)) {
            $typeCondition = sprintf("`type` = '%s'", $this->getDocumentType());

            if ($this->getIndexListingCondition() !== null) {
                $listingInstance->setCondition(
                    sprintf('%s AND (%s)', $typeCondition, $this->getIndexListingCondition())
                );
            } else {
                $listingInstance->setCondition($typeCondition);
            }
        }

        return $listingInstance;
    }

    final public static function getElasticsearchId(AbstractElement $element): string
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

    protected function getDocumentType(): ?string
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

    /**
     * @return class-string
     */
    protected function getListingClass(): string
    {
        try {
            return match ($this->getType()) {
                DocumentType::ASSET => AssetListing::class,
                DocumentType::DOCUMENT => DocumentListing::class,
                DocumentType::DATA_OBJECT, DocumentType::VARIANT => $this->getDataObjectListingClass(),
            };
        } catch (UnhandledMatchError) {
            throw new UnknownPimcoreElementType($this->getType()->value);
        }
    }

    protected function getIndexListingCondition(): ?string
    {
        return null;
    }

    protected function includeUnpublishedElementsInListing(): bool
    {
        return false;
    }

    /**
     * @return class-string
     */
    private function getDataObjectListingClass(): string
    {
        $subType = $this->getSubType();
        $className = $subType . '\Listing';

        if (!class_exists($className)) {
            throw new PimcoreListingClassNotFoundException($subType);
        }

        return $className;
    }
}