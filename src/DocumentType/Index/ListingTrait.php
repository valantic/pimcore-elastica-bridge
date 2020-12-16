<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Model\DataObject\Listing;
use Pimcore\Model\Listing\AbstractListing;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

trait ListingTrait
{
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

        return $listingInstance;
    }
}
