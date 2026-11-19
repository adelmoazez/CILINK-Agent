<?php

declare(strict_types=1);

/**
 * Verify the minimum Composer and scoped-adapter files required by a CILINK Agent release.
 *
 * Usage: php bin/verify-vendor-manifests.php [vendor-dir]
 */

$vendor = rtrim((string) ($argv[1] ?? dirname(__DIR__) . '/vendor'), DIRECTORY_SEPARATOR);
$required = [
    $vendor . '/autoload_packages.php',
    $vendor . '/novamira/mcp-adapter/autoload.php',
    $vendor . '/novamira/mcp-adapter/includes/Core/McpAdapter.php',
];

$missing = array_values(array_filter($required, static fn(string $path): bool => !is_file($path)));

if ($missing !== []) {
    fwrite(STDERR, "CILINK Agent vendor verification failed. Missing files:\n");
    foreach ($missing as $path) {
        fwrite(STDERR, " - {$path}\n");
    }
    exit(1);
}

if (is_dir($vendor . '/wordpress/mcp-adapter')) {
    fwrite(STDERR, "The unscoped WordPress MCP Adapter is still present in the release package.\n");
    exit(1);
}

echo "CILINK Agent vendor files verified.\n";
