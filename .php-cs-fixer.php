<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PHP81Migration' => true,
        'declare_strict_types' => true,
        'final_class' => true,
        'no_superfluous_phpdoc_tags' => true,
        'ordered_imports' => true,
        'phpdoc_to_comment' => false,
        'strict_comparison' => true,
    ])
    ->setFinder($finder);
