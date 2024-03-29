<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;

class ValanticElasticaBridgeBundle extends AbstractPimcoreBundle
{
    use PackageVersionTrait;

    protected function getComposerPackageName(): string
    {
        $composer = file_get_contents(__DIR__ . '/../composer.json');

        if ($composer === false) {
            throw new \RuntimeException();
        }

        return json_decode($composer, null, 512, \JSON_THROW_ON_ERROR)->name;
    }
}
