<?php

require_once __DIR__ . '/vendor/autoload.php';

return Valantic\PhpCsFixerConfig\ConfigFactory::createValanticConfig([
    'declare_strict_types' => false,
    'phpdoc_to_comment' => false,
])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([
                __DIR__ . '/src',
                __DIR__ . '/tests',
                __DIR__ . '/docs/example',
            ])
            ->name(['*.php'])
    )
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRiskyAllowed(true);
