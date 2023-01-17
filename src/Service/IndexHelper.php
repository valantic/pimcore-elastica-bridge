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
     * @param \Generator<string,IndexInterface,void,void> $indices
     *
     * @return IndexInterface[]
     */
    public function matchingIndicesForElement(\Generator $indices, AbstractElement $element): array
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
     * @internal
     */
    public function isIdInIndex(string $id, IndexInterface $index): bool
    {
        try {
            $index->getElasticaIndex()->getDocument($id);
        } catch (NotFoundException) {
            return false;
        }

        return true;
    }
}
