<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Model\DataObject;
use Pimcore\Model\Listing\AbstractListing;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

/**
 * Provides a default implementation for getListingInstance
 */
trait ListingTrait
{
    abstract public function getType(): string;

    abstract public function getSubType(): string;

    abstract public function getDocumentType(): ?string;

    abstract public function getListingClass(): string;

    abstract public function treatVariantsAsSeparateEntities(): bool;

    public function getIndexListingCondition(): ?string
    {
        return null;
    }

    public function getListingInstance(IndexInterface $index): AbstractListing
    {
        /** @var AbstractListing $listingClass */
        $listingClass = $this->getListingClass();

        $listingInstance = new $listingClass();
        $listingInstance->setCondition($this->getIndexListingCondition());

        if ($this->getType() === DocumentInterface::TYPE_OBJECT && $this->treatVariantsAsSeparateEntities()) {
            $listingInstance->setObjectTypes([DataObject\AbstractObject::OBJECT_TYPE_OBJECT, DataObject\AbstractObject::OBJECT_TYPE_VARIANT]);
        }

        if ($this->getType() === DocumentInterface::TYPE_DOCUMENT) {
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
