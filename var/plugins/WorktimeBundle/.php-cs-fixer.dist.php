<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['vendor'])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => false,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'header_comment' => [
            'header' => "This file is part of the WorktimeBundle for Kimai.\nFor the full copyright and license information, please view the LICENSE\nfile that was distributed with this source code.",
            'location' => 'after_open',
            'separate' => 'both',
        ],
    ])
    ->setFinder($finder)
;
