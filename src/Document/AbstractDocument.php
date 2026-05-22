<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Archive;
use Pimcore\Model\Asset\Audio;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\Asset\Listing;
use Pimcore\Model\Asset\Text;
use Pimcore\Model\Asset\Unknown;
use Pimcore\Model\Asset\Video;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Folder;
use Pimcore\Model\Document as PimcoreDocument;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Exception\DocumentType\PimcoreListingClassNotFoundException;
use Valantic\ElasticaBridgeBundle\Exception\DocumentType\UnknownPimcoreElementType;
use Valantic\ElasticaBridgeBundle\Index\DocumentContext;
use Valantic\ElasticaBridgeBundle\Index\IndexContext;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

/**
 * @template TElement of AbstractElement
 *
 * @implements DocumentInterface<TElement>
 */
abstract class AbstractDocument implements DocumentInterface
{
    final public static function getElasticsearchId(AbstractElement $element): string
    {
        $documentType = DocumentType::tryFrom($element->getType());

        if ($element instanceof Asset) {
            return DocumentType::ASSET->value . $element->getId();
        }

        if ($element instanceof PimcoreDocument) {
            return DocumentType::DOCUMENT->value . $element->getId();
        }

        if (
            $element instanceof Folder
            || in_array($documentType, DocumentType::casesDataObjects(), true)
        ) {
            return DocumentType::DATA_OBJECT->value . $element->getId();
        }

        throw new UnknownPimcoreElementType($documentType?->value);
    }

    public static function getIdForContext(AbstractElement $element, DocumentContext $documentContext): string
    {
        $parts = array_filter([
            static::getElasticsearchId($element),
            $documentContext->language,
            $documentContext->country,
        ]);

        return implode('_', $parts);
    }

    public function getNormalizedForContext(AbstractElement $element, IndexContext $indexContext, DocumentContext $documentContext): array
    {
        return $this->getNormalized($element);
    }

    public function getDocumentContexts(AbstractElement $element, IndexContext $indexContext): array
    {
        return $this->shouldIndex($element) ? [new DocumentContext()] : [];
    }

    public function treatObjectVariantsAsDocuments(): bool
    {
        return false;
    }

    public function getListingInstance(IndexInterface $index): DataObject\Listing|PimcoreDocument\Listing|Listing
    {
        /** @var class-string<DataObject\Listing|PimcoreDocument\Listing|Listing> $listingClass */
        $listingClass = $this->getListingClass();

        $listingInstance = new $listingClass();

        if ($this->getPathCondition() !== null) {
            $listingInstance->addConditionParam('path LIKE ?', $this->getPathCondition() . '%');
        }

        if ($this->getIndexListingCondition() !== null) {
            $listingInstance->setCondition($this->getIndexListingCondition());
        }

        if (in_array($this->getType(), DocumentType::casesPublishedState(), true)) {
            /** @var PimcoreDocument\Listing|DataObject\Listing $listingInstance */
            $listingInstance->setUnpublished($this->includeUnpublishedElementsInListing());
        }

        if ($this->getType() === DocumentType::DATA_OBJECT) {
            /** @var DataObject\Listing $listingInstance */
            if ($this->treatObjectVariantsAsDocuments()) {
                $listingInstance->setObjectTypes([
                    AbstractObject::OBJECT_TYPE_OBJECT,
                    AbstractObject::OBJECT_TYPE_VARIANT,
                ]);
            }

            if (!$this->treatObjectVariantsAsDocuments()) {
                $listingInstance->setObjectTypes([
                    AbstractObject::OBJECT_TYPE_OBJECT,
                ]);
            }
        }

        if ($this->getSubType() !== null && in_array($this->getType(), DocumentType::casesSubTypeListing(), true)) {
            $typeCondition = sprintf("`type` = '%s'", $this->getDocumentType());

            if ($this->getIndexListingCondition() !== null) {
                $listingInstance->setCondition(
                    sprintf('%s AND (%s)', $typeCondition, $this->getIndexListingCondition()),
                );
            } else {
                $listingInstance->setCondition($typeCondition);
            }
        }

        return $listingInstance;
    }

    protected function getDocumentType(): ?string
    {
        if (!in_array($this->getType(), DocumentType::casesSubTypeListing(), true)) {
            return null;
        }

        $subType = $this->getSubType();

        if ($subType === null) {
            return null;
        }

        $candidate = null;

        if ($this->getType() === DocumentType::DOCUMENT) {
            $candidate = $this->getTypeMappingForDocuments()[$subType] ?? null;

            if (!in_array($candidate, PimcoreDocument::getTypes(), true)) {
                throw new UnknownPimcoreElementType($candidate);
            }
        }

        if ($this->getType() === DocumentType::ASSET) {
            $candidate = $this->getTypeMappingForAssets()[$subType] ?? null;

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
                DocumentType::ASSET => Listing::class,
                DocumentType::DOCUMENT => PimcoreDocument\Listing::class,
                DocumentType::DATA_OBJECT, DocumentType::VARIANT => $this->getDataObjectListingClass(),
            };
        } catch (\UnhandledMatchError) {
            throw new UnknownPimcoreElementType($this->getType()->value);
        }
    }

    protected function getPathCondition(): ?string
    {
        return null;
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
     * @return array<class-string,string>
     */
    protected function getTypeMappingForAssets(): array
    {
        return [
            Archive::class => 'archive',
            Audio::class => 'audio',
            Asset\Document::class => 'document',
            Asset\Folder::class => 'folder',
            Image::class => 'image',
            Text::class => 'text',
            Unknown::class => 'unknown',
            Video::class => 'video',
        ];
    }

    /**
     * @return array<class-string,string>
     */
    protected function getTypeMappingForDocuments(): array
    {
        // the bundles providing these classes are optional and not installed in this package's dev dependencies
        /** @var array<string,string> $possibleBundleTypes */
        $possibleBundleTypes = [
            'Pimcore\Bundle\NewsletterBundle\Model\Document\Newsletter' => 'newsletter',
            'Pimcore\Bundle\WebToPrintBundle\Model\Document\Printpage' => 'printpage',
            'Pimcore\Bundle\WebToPrintBundle\Model\Document\Printcontainer' => 'printcontainer',
        ];

        /** @var array<class-string,string> $availableBundleTypes */
        $availableBundleTypes = [];

        foreach ($possibleBundleTypes as $className => $mapped) {
            if (!class_exists($className)) {
                continue;
            }

            $availableBundleTypes[$className] = $mapped;
        }

        return [
            PimcoreDocument\Folder::class => 'folder',
            PimcoreDocument\Page::class => 'page',
            PimcoreDocument\Snippet::class => 'snippet',
            PimcoreDocument\Link::class => 'link',
            PimcoreDocument\Hardlink::class => 'hardlink',
            PimcoreDocument\Email::class => 'email',
            ...$availableBundleTypes,
        ];
    }

    /**
     * @return class-string
     */
    private function getDataObjectListingClass(): string
    {
        $subType = $this->getSubType();

        if ($subType === null) {
            return DataObject\Listing::class;
        }

        $className = $subType . '\Listing';

        if (!class_exists($className)) {
            throw new PimcoreListingClassNotFoundException($subType);
        }

        return $className;
    }
}
