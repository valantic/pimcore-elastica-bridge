<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastica\Exception\NotFoundException;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class IndexHelper
{
    /**
     * Returns an array of indices that could contain $element.
     *
     * @param IndexInterface[] $indices
     * @param AbstractElement $element
     *
     * @return IndexInterface[]
     */
    public function matchingIndicesForElement(array $indices, AbstractElement $element): array
    {
        return array_filter($indices, fn(IndexInterface $index): bool => $index->isElementAllowedInIndex($element));
    }

    /**
     * Checks whether a given ID is in an index.
     *
     * @param string $id
     * @param IndexInterface $index
     *
     * @return bool
     *
     * @internal
     */
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
