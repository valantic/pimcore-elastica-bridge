<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

class BridgeHelper
{
    /**
     * @param iterable<object> $iterables
     *
     * @return array<string,object>
     */
    public function iterableToArray(iterable $iterables): array
    {
        $arr = [];

        foreach ($iterables as $iterable) {
            $arr[$iterable::class] = $iterable;
        }

        return $arr;
    }
}
