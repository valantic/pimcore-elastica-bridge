<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Model\DataObject\Listing;
use Pimcore\Model\Listing\AbstractListing;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

trait ListingTrait
{
    abstract public function getType(): string;

    abstract public function getSubType(): string;

    abstract public function getDocumentType(): ?string;

    abstract public function getListingClass(): string;

    public function getIndexListingCondition(): ?string
    {
        return null;
    }

    public function getListingInstance(IndexInterface $index): AbstractListing
    {
        /** @var Listing $listingClass */
        $listingClass = $this->getListingClass();

        $listingInstance = new $listingClass();
        $listingInstance->setCondition($this->getIndexListingCondition());
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
