<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Gate measured PHPUnit source-line coverage. Other acceptance suites
 * exercise HTTP, cURL and Docker in separate processes; this percentage
 * must never be represented as complete system behavior coverage.
 */
$arguments = $_SERVER['argv'] ?? [];
if (PHP_SAPI !== 'cli' || count($arguments) !== 3 || !is_file($arguments[1])) {
    fwrite(STDERR, "Usage: php tools/check-coverage.php coverage.xml MINIMUM_PERCENT\n");
    exit(2);
}
$minimum = filter_var($arguments[2], FILTER_VALIDATE_FLOAT);
if ($minimum === false || $minimum < 0 || $minimum > 100) {
    fwrite(STDERR, "Invalid coverage threshold\n");
    exit(2);
}
try {
    $document = @simplexml_load_file($arguments[1], SimpleXMLElement::class, LIBXML_NONET);
    if (!$document instanceof SimpleXMLElement || !isset($document->project->metrics)) {
        throw new RuntimeException('Invalid Clover report');
    }
    $attrs = $document->project->metrics->attributes();
    $total = isset($attrs['statements']) ? filter_var((string)$attrs['statements'], FILTER_VALIDATE_INT) : false;
    $covered = isset($attrs['coveredstatements']) ? filter_var((string)$attrs['coveredstatements'], FILTER_VALIDATE_INT) : false;
    if (!is_int($total) || !is_int($covered) || $total <= 0 || $covered < 0 || $covered > $total) {
        throw new RuntimeException('Invalid Clover line counts');
    }
    $percentage = 100 * $covered / $total;
    printf("PHPUnit source coverage %.2f%% (%d/%d lines)\n", $percentage, $covered, $total);
    if ($percentage < $minimum) {
        fwrite(STDERR, "Insufficient covered source lines\n");
        exit(1);
    }
} catch (Throwable) {
    fwrite(STDERR, "Coverage report cannot be validated\n");
    exit(2);
}
