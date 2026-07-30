<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];

if (!is_array($arguments)) {
    fwrite(STDERR, "Unable to read command-line arguments.\n");
    exit(2);
}

if (count($arguments) !== 3) {
    fwrite(STDERR, "Usage: php tools/assert-coverage.php <clover.xml> <minimum-percent>\n");
    exit(2);
}

[$script, $cloverPath, $minimumCoverage] = $arguments;

unset($script);

if (!is_string($cloverPath) || !is_string($minimumCoverage)) {
    fwrite(STDERR, "Command-line arguments must be strings.\n");
    exit(2);
}

if (!is_file($cloverPath)) {
    fwrite(STDERR, sprintf("Coverage file not found: %s\n", $cloverPath));
    exit(2);
}

$minimum = (float) $minimumCoverage;
$xml = simplexml_load_file($cloverPath);

if ($xml === false) {
    fwrite(STDERR, sprintf("Coverage file is not valid XML: %s\n", $cloverPath));
    exit(2);
}

$metrics = $xml->xpath('/coverage/project/metrics') ?: [];

if ($metrics === []) {
    fwrite(STDERR, "Coverage metrics missing from Clover report.\n");
    exit(2);
}

$projectMetrics = $metrics[0];
$attributes = $projectMetrics->attributes();
$coveredStatements = (int) ($attributes['coveredstatements'] ?? 0);
$statements = (int) ($attributes['statements'] ?? 0);
$coverage = $statements === 0 ? 100.0 : ($coveredStatements / $statements) * 100;

if ($coverage < $minimum) {
    fwrite(STDERR, sprintf("Line coverage %.2f%% is below required %.2f%%.\n", $coverage, $minimum));
    exit(1);
}

printf("Line coverage %.2f%% meets required %.2f%%.\n", $coverage, $minimum);
