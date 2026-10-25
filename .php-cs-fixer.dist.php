<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()->in(__DIR__.'/src')->in(__DIR__.'/public')->in(__DIR__.'/tests/phpunit')->in(__DIR__.'/bin')->in(__DIR__.'/tools');
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        'no_trailing_whitespace' => true,
        'no_closing_tag' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
