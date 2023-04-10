<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Repository;

use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface;

/** @extends AbstractRepository<IndexInterface> */
class IndexRepository extends AbstractRepository
{
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
}
