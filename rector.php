<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
    )
    ->withPhpSets()
    ->withAttributesSets(symfony: true, phpunit: true)
    ->withComposerBased(phpunit: true, symfony: true, laravel: true)
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
    ])
    ->withRules([
    ])
    ->withImportNames(importShortClasses: false)
    ->withRootFiles();
