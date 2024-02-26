<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Repository;

use Valantic\ElasticaBridgeBundle\Exception\Repository\ItemNotFoundInRepositoryException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface;

/**
 * @extends AbstractRepository<IndexInterface>
 *
 * @internal
 */
class IndexRepository extends AbstractRepository
{
    /**
     * @return \Generator<string,IndexInterface,void,void>
     */
    public function flattenedAll(): \Generator
    {
        foreach ($this->all() as $indexConfig) {
            if ($indexConfig instanceof TenantAwareInterface) {
                foreach ($indexConfig->getTenants() as $tenant) {
                    $local = clone $indexConfig;
                    $local->setTenant($tenant);

                    yield $local->getName() => clone $local;
                }
            } else {
                yield $indexConfig->getName() => $indexConfig;
            }
        }
    }

    public function flattenedGet(string $key): IndexInterface
    {
        foreach ($this->flattenedAll() as $candidateKey => $index) {
            if ($candidateKey === $key) {
                return $index;
            }
        }

        throw new ItemNotFoundInRepositoryException($key);
    }
}
