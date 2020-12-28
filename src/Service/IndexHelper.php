<?php

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastica\Exception\NotFoundException;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class IndexHelper
{
    /**
     * @param IndexInterface[] $indices
     * @param AbstractElement $element
     *
     * @return IndexInterface[]
     */
    public function matchingIndicesForElement(array $indices, AbstractElement $element): array
    {
        return array_filter($indices, fn(IndexInterface $index): bool => $index->isElementAllowedInIndex($element));
    }

    public function isIdInIndex(string $id, IndexInterface $index): bool
    {
        try {
            $index->getElasticaIndex()->getDocument($id);
        } catch (NotFoundException $e) {
            return false;
        }

        return true;
    }
}
