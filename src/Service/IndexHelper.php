<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastica\Exception\NotFoundException;
use Generator;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class IndexHelper
{
    /**
     * Returns an array of indices that could contain $element.
     *
     * @param Generator $indices
     * @param AbstractElement $element
     *
     * @return IndexInterface[]
     */
    public function matchingIndicesForElement(Generator $indices, AbstractElement $element): array
    {
        $matching = [];
        foreach ($indices as $index) {
            /** @var IndexInterface $index */
            if ($index->isElementAllowedInIndex($element)) {
                $matching[] = $index;
            }
        }

        return $matching;
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
