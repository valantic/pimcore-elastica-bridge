<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Repository;

abstract class AbstractRepository
{
    /**
     * @param iterable<object> $iterables
     *
     * @return array<string,object>
     */
    protected function iterableToArray(iterable $iterables): array
    {
        $arr = [];

        foreach ($iterables as $iterable) {
            $arr[$iterable::class] = $iterable;
        }

        return $arr;
    }
}
