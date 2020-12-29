<?php

namespace Valantic\ElasticaBridgeBundle\Repository;

use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Service\BridgeHelper;

class IndexRepository
{
    /**
     * @var IndexInterface[]
     */
    protected array $indices;

    public function __construct(iterable $indices, BridgeHelper $bridgeHelper)
    {
        $this->indices = $bridgeHelper->iterableToArray($indices);
    }

    /**
     * @return IndexInterface[]
     */
    public function all(): array
    {
        return $this->indices;
    }

    public function get(string $key): IndexInterface
    {
        return $this->indices[$key];
    }
}
