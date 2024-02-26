<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Foreach_\SimplifyForeachToCoalescingRector;
use Rector\CodeQuality\Rector\FuncCall\SingleInArrayToCompareRector;
use Rector\CodingStyle\Rector\FuncCall\CountArrayToEmptyArrayComparisonRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php80\Rector\FunctionLike\MixedTypeRector;

return RectorConfig::configure()
    ->withPhpSets()
    ->withPreparedSets(
        codeQuality: true,
    )
    ->withAttributesSets(
        symfony: true,
        doctrine: true,
    )
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSkip([
        CountArrayToEmptyArrayComparisonRector::class,
        StringClassNameToClassConstantRector::class => [
            'src/Elastica/Client/ElasticsearchClientFactory.php',
        ],
        MixedTypeRector::class => [
            'src/Document/DataObjectNormalizerTrait.php',
            'src/Index/IndexInterface.php',
        ],
        SingleInArrayToCompareRector::class => [
            'src/Command/NonBundleIndexTrait.php',
        ],
        SimplifyForeachToCoalescingRector::class => [
            'src/Repository/IndexRepository.php',
        ],
    ])
    ->withRootFiles();
