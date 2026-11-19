<?php

declare(strict_types=1);

/**
 * Build the isolated MCP Adapter namespace used by CILINK Agent.
 *
 * Composer installs the upstream package as WP\\MCP. The plugin ships a namespaced copy so it
 * cannot accidentally load another plugin's incompatible MCP Adapter classes.
 *
 * Usage: php bin/scope-mcp-adapter.php [vendor-dir] [--delete-originals]
 */

$vendor = $argv[1] ?? dirname(__DIR__) . '/vendor';
$vendor = rtrim((string) $vendor, DIRECTORY_SEPARATOR);
$source = $vendor . '/wordpress/mcp-adapter/includes';
$packageRoot = $vendor . '/novamira/mcp-adapter';
$target = $packageRoot . '/includes';

if (!is_dir($source)) {
    fwrite(STDERR, "MCP Adapter source directory not found: {$source}\n");
    exit(1);
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
        $item->isDir() && !$item->isLink()
            ? rmdir($item->getPathname())
            : unlink($item->getPathname());
    }

    rmdir($path);
}

cilink_remove_tree($packageRoot);
if (!mkdir($target, 0775, true) && !is_dir($target)) {
    fwrite(STDERR, "Could not create scoped MCP Adapter directory: {$target}\n");
    exit(1);
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
        $contents = str_replace('WP\\MCP', 'Novamira\\Vendor\\WP\\MCP', $contents);
    }

    if (file_put_contents($destination, $contents) === false) {
        throw new RuntimeException("Could not write: {$destination}");
    }
}

$autoload = <<<'PHP'
<?php

declare(strict_types=1);

$prefix = 'Novamira\\Vendor\\WP\\MCP\\';
$baseDir = __DIR__ . '/includes/';

spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
PHP;

if (file_put_contents($packageRoot . '/autoload.php', $autoload) === false) {
    throw new RuntimeException('Could not write scoped MCP Adapter autoloader.');
}

if (in_array('--delete-originals', $argv, true)) {
    cilink_remove_tree($vendor . '/wordpress/mcp-adapter');
}

echo "Scoped MCP Adapter prepared at {$packageRoot}\n";
