<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Command;

trait NonBundleIndexTrait
{
    protected function shouldProcessNonBundleIndex(string $name): bool
    {
        return $name !== '.geoip_databases';
    }
}
