<?php

// PHP-CS-Fixer 2.x syntax
return PhpCsFixer\Config::create()
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'concat_space' => ['spacing' => 'one'],
        'array_syntax' => ['syntax' => 'short'],
        'simplified_null_return' => false,
        'phpdoc_align' => false,
        'phpdoc_separation' => false,
        'phpdoc_to_comment' => false,
        'cast_spaces' => false,
        'blank_line_after_opening_tag' => false,
        'single_blank_line_before_namespace' => false,
        'phpdoc_annotation_without_dot' => false,
        'phpdoc_no_alias_tag' => false,
        'space_after_semicolon' => false,
        'yoda_style' => false,
        'no_break_comment' => false,
        'native_function_invocation' => false,
        'native_constant_invocation' => false,
        'phpdoc_types_order' => false,
        'php_unit_mock_short_will_return' => false,
        'php_unit_construct' => false,
        'php_unit_dedicate_assert' => true,
        'php_unit_dedicate_assert_internal_type' => true,
        'standardize_increment' => false,
        'fopen_flags' => false,
        'self_accessor' => false,
    ])
    ->setRiskyAllowed(true)
;
