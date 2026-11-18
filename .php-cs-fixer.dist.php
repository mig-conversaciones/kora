<?php

declare(strict_types=1);

/** Estilo PSR-12 con declare(strict_types=1) obligatorio (RNF-012). */
$archivos = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/config', __DIR__ . '/cli', __DIR__ . '/public', __DIR__ . '/tests', __DIR__ . '/herramientas'])
    ->exclude(['Vistas'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_trailing_whitespace_in_comment' => true,
    ])
    ->setFinder($archivos);
