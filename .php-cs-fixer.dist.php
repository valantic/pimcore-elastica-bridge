<?php

$finder = PhpCsFixer\Finder::create()
    ->in('src')
    ->in('example');

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP80Migration' => true,
        '@PHP80Migration:risky' => true,
        '@PSR12' => true,
        '@PSR12:risky' => true,
        'align_multiline_comment' => true,
        'array_indentation' => true,
        'concat_space' => ['spacing' => 'one'],
        'function_declaration' => ['closure_function_spacing' => 'none'],
        'increment_style' => ['style' => 'post'],
        'method_chaining_indentation' => true,
        'multiline_comment_opening_closing' => true,
        'native_function_invocation' => false,
        'no_null_property_initialization' => true,
        'no_superfluous_phpdoc_tags' => false,
        'no_unset_on_property' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'operator_linebreak' => ['only_booleans' => true],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'phpdoc_tag_casing' => true,
        'phpdoc_to_comment' => false,
        'regular_callable_call' => true,
        'return_assignment' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'parameters', 'match']],
        'yoda_style' => false,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setUsingCache(true);
