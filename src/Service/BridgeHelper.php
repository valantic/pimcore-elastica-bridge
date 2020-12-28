<?php

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
            $arr[get_class($iterable)] = $iterable;
        }

        return $arr;
    }
}
