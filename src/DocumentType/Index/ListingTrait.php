<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Model\DataObject;
use Pimcore\Model\Listing\AbstractListing;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

/**
 * Provides a default implementation for getListingInstance.
 */
trait ListingTrait
{
    abstract public function getType(): DocumentType;

    abstract public function getSubType(): string;

    abstract public function getDocumentType(): ?string;

    abstract public function getListingClass(): string;

    abstract public function treatObjectVariantsAsDocuments(): bool;

    public function includeUnpublishedElementsInListing(): bool
    {
        return false;
    }

    public function getIndexListingCondition(): ?string
    {
        return null;
    }

    public function getListingInstance(IndexInterface $index): AbstractListing
    {
        /** @var class-string<AbstractListing> $listingClass */
        $listingClass = $this->getListingClass();

        /** @var AbstractListing $listingInstance */
        $listingInstance = new $listingClass();
        $listingInstance->setCondition($this->getIndexListingCondition());

        if (in_array($this->getType(), DocumentType::casesPublishedState(), true)) {
            $listingInstance->setUnpublished($this->includeUnpublishedElementsInListing());
        }

        if ($this->getType() === DocumentType::DATA_OBJECT && $this->treatObjectVariantsAsDocuments()) {
            $listingInstance->setObjectTypes([DataObject\AbstractObject::OBJECT_TYPE_OBJECT, DataObject\AbstractObject::OBJECT_TYPE_VARIANT]);
        }

        if (in_array($this->getType(), DocumentType::casesSubTypeListing(), true)) {
            $typeCondition = sprintf("`type` = '%s'", $this->getDocumentType());
            if ($this->getIndexListingCondition() !== null) {
                $listingInstance->setCondition(sprintf('%s AND (%s)', $typeCondition, $this->getIndexListingCondition()));
            } else {
                $listingInstance->setCondition($typeCondition);
            }
        }

        return $listingInstance;
    }
}
