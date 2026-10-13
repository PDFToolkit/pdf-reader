<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples']);

return (new Config())
    ->setRules(['@PSR12' => true, 'declare_strict_types' => true])
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/.cache/php-cs-fixer')
    ->setFinder($finder);
