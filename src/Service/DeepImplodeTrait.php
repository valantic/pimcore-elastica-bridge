<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

/**
 * @see https://gist.github.com/SeanCannon/6585889
 */
trait DeepImplodeTrait
{
    /**
     * Recursively implodes an array with the newline character. Useful for storing Page HTML in a string.
     */
    protected function deepImplode(array $arr): string
    {
        return implode("\n", $this->deepFlatten($arr));
    }

    /**
     * @internal
     */
    protected function deepFlatten(array $arr): array
    {
        return array_reduce(
            $arr,
            fn ($carry, $item) => is_array($item)
                ? [...$carry, ...$this->deepFlatten($item)]
                : [...$carry, $item],
            [],
        );
    }
}
