<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

trait NonBundleIndexTrait
{
    protected function shouldProcessNonBundleIndex(string $name): bool
    {
        return !in_array($name, ['.geoip_databases'], true);
    }
}
