<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Repository;

use Valantic\ElasticaBridgeBundle\Exception\Index\ItemNotFoundInRepositoryException;

/**
 * @template T
 */
abstract class AbstractRepository
{
    /** @var T[] */
    protected array $items;

    public function __construct(
        /** @var \Iterator<T> */
        protected iterable $iterables,
    ) {
    }

     /** @return T[] */
     public function all(): array
     {
         $this->items ??= $this->initializeItemsFromIterables();

         return $this->items;
     }

     /** @return T */
     public function get(string $key)
     {
         return $this->all()[$key] ?? throw new ItemNotFoundInRepositoryException($key);
     }

    /**
     * @return array<class-string<T>,T>
     */
    protected function initializeItemsFromIterables(): array
    {
        $arr = [];

        foreach ($this->iterables as $iterable) {
            $arr[$iterable::class] = $iterable;
        }

        return $arr;
    }
}
