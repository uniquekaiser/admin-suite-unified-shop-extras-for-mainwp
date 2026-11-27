<?php
declare(strict_types=1);

use Synergetic\MainWPProducts\Release\CatalogBuilder;
use Synergetic\MainWPProducts\Release\ProductPackage;
use Synergetic\MainWPProducts\Release\PublisherCatalog;

if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/../src/Release/PublisherCatalog.php';
require __DIR__ . '/../src/Release/ProductPackage.php';
require __DIR__ . '/../src/Release/CatalogBuilder.php';

function readLocalFile(string $file, int $limit): string
{
    $resolved = realpath($file);
    $size = $resolved !== false && is_file($resolved) ? filesize($resolved) : false;
    if ($size === false || $size > $limit || !is_readable($resolved)) {
        throw new InvalidArgumentException('Input file is missing, unreadable or outside the size limit.');
    }
    $contents = file_get_contents($resolved, false, null, 0, $limit + 1);
    if ($contents === false || strlen($contents) > $limit) {
        throw new InvalidArgumentException('Input could not be read within the size limit.');
    }
    return $contents;
}

function readKey(string $file): string
{
    $key = base64_decode(trim(readLocalFile($file, 256)), true);
    if ($key === false || strlen($key) !== 32) {
        throw new InvalidArgumentException('Key file must contain a base64-encoded 32-byte value.');
    }
    return $key;
}

function writeCatalog(string $output, string $document): void
{
    $folder = realpath(dirname($output));
    if ($folder === false || !is_dir($folder) || file_exists($output) || is_link($output)) {
        throw new InvalidArgumentException('Use a new catalog output path in an existing directory.');
    }
    $temporary = tempnam($folder, '.catalog-');
    if ($temporary === false) {
        throw new RuntimeException('Could not create a temporary catalog file.');
    }
    try {
        if (file_put_contents($temporary, $document) !== strlen($document) || !rename($temporary, $output)) {
            throw new RuntimeException('Could not write the catalog output.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function loadCatalog(string $catalog, string $key, ?string $minimum): PublisherCatalog
{
    $revision = filter_var($minimum ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($revision === false) {
        throw new InvalidArgumentException('Minimum catalog revision must be a positive integer.');
    }
    return PublisherCatalog::verify(readLocalFile($catalog, PublisherCatalog::MAX_DOCUMENT_BYTES), readKey($key), $revision);
}

try {
    $command = $argv[1] ?? '';
    if ($command === 'inspect' && $argc === 7) {
        $archive = ProductPackage::inspect($argv[5], $argv[2], $argv[3], $argv[4], $argv[6]);
        printf("Approved %s %s %s: %s, %d files.\n", $argv[2], $argv[3], $argv[4], $archive['basename'], count($archive['files']));
    } elseif ($command === 'build' && $argc === 5) {
        $configuration = PublisherCatalog::decodeObject(readLocalFile($argv[2], PublisherCatalog::MAX_DOCUMENT_BYTES));
        $seed = readKey($argv[3]);
        try {
            $document = CatalogBuilder::build($configuration, dirname((string) realpath($argv[2])), $seed);
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($seed);
            }
        }
        writeCatalog($argv[4], $document);
        printf("Wrote signed publisher catalog revision %d.\n", $configuration['revision']);
    } elseif ($command === 'verify' && ($argc === 4 || $argc === 5)) {
        $catalog = loadCatalog($argv[2], $argv[3], $argv[4] ?? null);
        printf("Verified publisher catalog revision %d.\n", $catalog->revision());
    } elseif ($command === 'verify-lite' && ($argc === 6 || $argc === 7)) {
        $catalog = loadCatalog($argv[2], $argv[3], $argv[6] ?? null);
        $release = $catalog->litePackage($argv[4]);
        if ($release === null) {
            throw new InvalidArgumentException('Lite installation is not published for this product.');
        }
        ProductPackage::inspect($argv[5], $argv[4], 'lite', $release['version'], $release['sha256']);
        printf("Verified %s Lite %s against catalog revision %d.\n", $argv[4], $release['version'], $catalog->revision());
    } elseif ($command === 'verify-tree' && ($argc === 7 || $argc === 8)) {
        $catalog = loadCatalog($argv[2], $argv[3], $argv[7] ?? null);
        ProductPackage::verifyInstalled($argv[6], $catalog->proManifest($argv[4], $argv[5]));
        printf("Verified installed %s Pro %s against catalog revision %d.\n", $argv[4], $argv[5], $catalog->revision());
    } else {
        fwrite(STDERR, "Usage:\n"
            . "  php tools/publisher-catalog.php inspect <product> <lite|pro> <version> <zip> <sha256>\n"
            . "  php tools/publisher-catalog.php build <configuration.json> <signing-seed-file> <catalog.json>\n"
            . "  php tools/publisher-catalog.php verify <catalog.json> <trusted-public-key-file> [minimum-revision]\n"
            . "  php tools/publisher-catalog.php verify-lite <catalog.json> <trusted-public-key-file> <product> <zip> [minimum-revision]\n"
            . "  php tools/publisher-catalog.php verify-tree <catalog.json> <trusted-public-key-file> <product> <version> <plugin-directory> [minimum-revision]\n");
        exit(2);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
