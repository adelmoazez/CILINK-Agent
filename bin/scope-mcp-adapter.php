<?php

declare(strict_types=1);

/**
 * Build isolated MCP Adapter and MCP Schema namespaces for CILINK Agent.
 *
 * Usage: php bin/scope-mcp-adapter.php [vendor-dir] [--delete-originals]
 */

$vendor = rtrim((string) ($argv[1] ?? dirname(__DIR__) . '/vendor'), DIRECTORY_SEPARATOR);
$adapterSource = $vendor . '/wordpress/mcp-adapter/includes';
$schemaSource = $vendor . '/wordpress/php-mcp-schema/src';
$packageRoot = $vendor . '/novamira/mcp-adapter';
$adapterTarget = $packageRoot . '/includes';
$schemaTarget = $packageRoot . '/schema';

foreach ([$adapterSource, $schemaSource] as $required) {
    if (!is_dir($required)) {
        fwrite(STDERR, "Required Composer package directory not found: {$required}\n");
        exit(1);
    }
}

function cilink_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}

/**
 * Copy a PSR-4 source tree while applying the private namespace and hook prefixes.
 *
 * @param array<string, string> $replacements
 */
function cilink_copy_scoped_tree(string $source, string $target, array $replacements): void
{
    if (!mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException("Could not create scoped directory: {$target}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $destination = $target . DIRECTORY_SEPARATOR . $relative;

        if ($item->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
                throw new RuntimeException("Could not create directory: {$destination}");
            }
            continue;
        }

        if (!$item->isFile()) {
            continue;
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create directory: {$directory}");
        }

        $contents = file_get_contents($item->getPathname());
        if ($contents === false) {
            throw new RuntimeException("Could not read: {$item->getPathname()}");
        }

        if (strtolower(pathinfo($destination, PATHINFO_EXTENSION)) === 'php') {
            $contents = str_replace(array_keys($replacements), array_values($replacements), $contents);
        }

        if (file_put_contents($destination, $contents) === false) {
            throw new RuntimeException("Could not write: {$destination}");
        }
    }
}

cilink_remove_tree($packageRoot);
cilink_copy_scoped_tree(
    $adapterSource,
    $adapterTarget,
    [
        'WP\\MCP' => 'Novamira\\Vendor\\WP\\MCP',
        'WP\\McpSchema' => 'Novamira\\Vendor\\WP\\McpSchema',
        'mcp_adapter_' => 'novamira_mcp_adapter_',
        'mcp-adapter/' => 'novamira-mcp-adapter/',
    ],
);
cilink_copy_scoped_tree(
    $schemaSource,
    $schemaTarget,
    [
        'WP\\McpSchema' => 'Novamira\\Vendor\\WP\\McpSchema',
    ],
);

$autoload = <<<'PHP'
<?php

declare(strict_types=1);

$vendorRoot = dirname(__DIR__, 2);
$composerAutoload = $vendorRoot . '/autoload.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

$prefixes = [
    'Novamira\\Vendor\\WP\\MCP\\' => __DIR__ . '/includes/',
    'Novamira\\Vendor\\WP\\McpSchema\\' => __DIR__ . '/schema/',
];

spl_autoload_register(static function (string $class) use ($prefixes): void {
    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
PHP;

if (file_put_contents($packageRoot . '/autoload.php', $autoload) === false) {
    throw new RuntimeException('Could not write the scoped MCP Adapter autoloader.');
}

if (in_array('--delete-originals', $argv, true)) {
    cilink_remove_tree($vendor . '/wordpress/mcp-adapter');
    cilink_remove_tree($vendor . '/wordpress/php-mcp-schema');
}

echo "Scoped MCP Adapter and MCP Schema prepared at {$packageRoot}\n";
