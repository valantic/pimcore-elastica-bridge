<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Repository;

use Valantic\ElasticaBridgeBundle\Exception\Index\ItemNotFoundInRepositoryException;

/**
 * @template TItem
 */
abstract class AbstractRepository
{
    /** @var TItem[] */
    protected array $items;

    public function __construct(
        /** @var \Iterator<TItem> */
        protected iterable $iterables,
    ) {
    }

    /** @return TItem */
    public function get(string $key)
    {
        return $this->all()[$key] ?? throw new ItemNotFoundInRepositoryException($key);
    }

    /** @return TItem[] */
    protected function all(): array
    {
        $this->items ??= $this->initializeItemsFromIterables();

        return $this->items;
    }

    /**
     * @return array<class-string<TItem>,TItem>
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
