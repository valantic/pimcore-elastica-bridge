<?php

namespace Valantic\ElasticaBridgeBundle\Service;

/**
 * @see https://gist.github.com/SeanCannon/6585889
 */
trait DeepImplodeTrait
{
    protected function deepImplode(array $arr): string
    {
        return implode("\n", $this->deepFlatten($arr));
    }

    protected function deepFlatten(array $arr): array
    {
        return array_reduce($arr,
            fn($carry, $item) => is_array($item)
                ? [...$carry, ...$this->deepFlatten($item)]
                : [...$carry, $item]
            , []
        );
    }
}
