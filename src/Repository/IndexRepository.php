<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Repository;

use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface;

/**
 * Used for typehinting. Contains an array of all IndexInterface implementations.
 *
 * @see IndexInterface
 */
class IndexRepository extends AbstractRepository
{
    /**
     * @var IndexInterface[]
     */
    private readonly array $indices;

    public function __construct(iterable $indices)
    {
        $this->indices = $this->iterableToArray($indices);
    }

    /**
     * @internal generally, usage is discouraged
     *
     * @see IndexRepository::flattened()
     *
     * @return IndexInterface[]
     */
    public function all(): array
    {
        return $this->indices;
    }

    /**
     * @return \Generator<string,IndexInterface,void,void>
     */
    public function flattened(): \Generator
    {
        foreach ($this->all() as $indexConfig) {
            if ($indexConfig instanceof TenantAwareInterface) {
                foreach ($indexConfig->getTenants() as $tenant) {
                    $indexConfig->setTenant($tenant);

                    yield $indexConfig->getName() => clone $indexConfig;
                }
            } else {
                yield $indexConfig->getName() => $indexConfig;
            }
        }
    }

    public function get(string $key): IndexInterface
    {
        return $this->indices[$key];
    }
}
