<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\Release;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class ProductPackage
{
    public static function inspect(string $path, string $product, string $edition, string $version, string $expectedDigest): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new InvalidArgumentException('The Zip extension is required to inspect release archives.');
        }
        $identity = PublisherCatalog::PRODUCTS[$product] ?? null;
        if ($identity === null || !in_array($edition, ['lite', 'pro'], true) || !PublisherCatalog::validVersion($version)
            || !PublisherCatalog::validDigest($expectedDigest)) {
            throw new InvalidArgumentException('Invalid product, edition, version or expected digest.');
        }
        $bytes = is_file($path) ? filesize($path) : false;
        if ($bytes === false || $bytes <= 0 || $bytes > PublisherCatalog::MAX_ZIP_BYTES || !is_readable($path)) {
            throw new InvalidArgumentException('Release ZIP is missing, unreadable or outside the size limit.');
        }
        self::verifyZipDigest($path, $expectedDigest);

        $archive = new ZipArchive();
        if ($archive->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new InvalidArgumentException('Release ZIP could not be opened.');
        }
        try {
            $basename = $identity[$edition];
            $mainIndex = $archive->locateName($basename);
            if ($mainIndex === false) {
                throw new InvalidArgumentException('Release ZIP does not contain the required plugin basename: ' . $basename);
            }
            if ($archive->numFiles > PublisherCatalog::MAX_FILES) {
                throw new InvalidArgumentException('Release ZIP contains too many entries.');
            }
            $root = explode('/', $basename, 2)[0];
            $files = self::readFileManifest($archive, $root);
            $main = $archive->getFromIndex($mainIndex, 512 * 1024);
            self::verifyPluginHeader($main, $version);
            $buildSource = $main;
            if ($product === 'unified-shop-extras' && isset($files['uwe-runtime.php'])) {
                $buildSource = self::uweBuildSource($archive, $root, $main, $edition, $files);
            }
            self::verifyBuildMarker($buildSource, $identity['marker'], $edition);
        } finally {
            $archive->close();
        }
        self::verifyZipDigest($path, $expectedDigest);

        return ['basename' => $basename, 'sha256' => $expectedDigest, 'bytes' => $bytes, 'files' => $files];
    }

    /** The manifest must come from PublisherCatalog::proManifest(). */
    public static function verifyInstalled(string $directory, array $manifest): void
    {
        $root = is_dir($directory) && !is_link($directory) ? realpath($directory) : false;
        $slug = explode('/', $manifest['basename'], 2)[0];
        if ($root === false || basename($root) !== $slug) {
            throw new InvalidArgumentException('Installed plugin directory does not match the approved Pro basename.');
        }
        $remaining = $manifest['files'];
        $directories = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $entries = new RecursiveIteratorIterator($directories, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($entries as $entry) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
            if ($entry->isLink() || !PublisherCatalog::validPath($relative)
                || !str_starts_with((string) $entry->getRealPath(), $root . DIRECTORY_SEPARATOR)) {
                throw new InvalidArgumentException('Installed plugin contains an unsafe path.');
            }
            if ($entry->isDir()) {
                continue;
            }
            if (!$entry->isFile() || !isset($remaining[$relative])) {
                throw new InvalidArgumentException('Installed plugin contains an unexpected file.');
            }
            $file = $remaining[$relative];
            $digest = $entry->getSize() === $file['bytes'] ? hash_file('sha256', $entry->getPathname()) : false;
            if ($digest === false || !hash_equals($file['sha256'], $digest)) {
                throw new InvalidArgumentException('An installed file does not match the Pro manifest: ' . $relative);
            }
            unset($remaining[$relative]);
        }
        if ($remaining !== []) {
            throw new InvalidArgumentException('An expected installed file is missing from the Pro plugin.');
        }
    }

    private static function verifyZipDigest(string $path, string $expectedDigest): void
    {
        $digest = hash_file('sha256', $path);
        if ($digest === false || !hash_equals($expectedDigest, $digest)) {
            throw new InvalidArgumentException('Release ZIP has a different SHA-256 digest.');
        }
    }

    private static function readFileManifest(ZipArchive $archive, string $root): array
    {
        $files = [];
        $paths = [];
        $total = 0;
        for ($index = 0; $index < $archive->numFiles; ++$index) {
            $entry = $archive->statIndex($index);
            if ($entry === false || !isset($entry['name'], $entry['size'])) {
                throw new InvalidArgumentException('Invalid ZIP entry.');
            }
            $name = $entry['name'];
            $directory = str_ends_with($name, '/');
            $path = $directory ? substr($name, 0, -1) : $name;
            if (!PublisherCatalog::validPath($path) || (!str_starts_with($path, $root . '/') && !($directory && $path === $root))) {
                throw new InvalidArgumentException('Release ZIP contains an unsafe or unexpected path.');
            }
            self::recordPath($paths, $path, $directory);
            self::verifyEntryType($archive, $index, $directory);
            if ($directory) {
                continue;
            }
            $total += $entry['size'];
            if ($entry['size'] < 0 || $total > PublisherCatalog::MAX_TREE_BYTES) {
                throw new InvalidArgumentException('Release ZIP exceeds the unpacked size limit.');
            }
            $stream = $archive->getStreamIndex($index);
            if (!is_resource($stream)) {
                throw new InvalidArgumentException('Release ZIP entry could not be read.');
            }
            try {
                $context = hash_init('sha256');
                $read = hash_update_stream($context, $stream, $entry['size'] + 1);
                if ($read !== $entry['size'] || !feof($stream)) {
                    throw new InvalidArgumentException('Release ZIP entry has an invalid expanded size.');
                }
                $relative = substr($path, strlen($root) + 1);
                $files[$relative] = ['sha256' => hash_final($context), 'bytes' => $read];
            } finally {
                fclose($stream);
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private static function recordPath(array &$paths, string $path, bool $directory): void
    {
        if (($paths[strtolower($path)][2] ?? false) === true) {
            throw new InvalidArgumentException('Release ZIP contains a duplicate path.');
        }
        $explicit = true;
        while ($path !== '.') {
            $key = strtolower($path);
            if (isset($paths[$key]) && [$path, $directory] !== array_slice($paths[$key], 0, 2)) {
                throw new InvalidArgumentException('Release ZIP contains conflicting paths.');
            }
            $paths[$key] = [$path, $directory, $explicit || ($paths[$key][2] ?? false)];
            $path = dirname($path);
            $directory = true;
            $explicit = false;
        }
    }

    private static function verifyEntryType(ZipArchive $archive, int $index, bool $directory): void
    {
        if (!$archive->getExternalAttributesIndex($index, $system, $attributes)) {
            throw new InvalidArgumentException('Release ZIP entry attributes are unreadable.');
        }
        if ($system === ZipArchive::OPSYS_UNIX) {
            $type = ($attributes >> 16) & 0170000;
            if ($type !== 0 && $type !== ($directory ? 0040000 : 0100000)) {
                throw new InvalidArgumentException('Release ZIP contains a symbolic link or unsupported entry type.');
            }
        }
    }

    private static function verifyPluginHeader(string|false $main, string $version): void
    {
        if ($main === false || !preg_match('/^[\s\/*#@]*Plugin Name:\s*\S/im', substr($main, 0, 8192))
            || !preg_match('/^[\s\/*#@]*Version:[ \t]*([^\r\n]+)/im', substr($main, 0, 8192), $header)
            || trim($header[1]) !== $version) {
            throw new InvalidArgumentException('Release ZIP plugin header version does not match the configured release.');
        }
    }

    private static function uweBuildSource(ZipArchive $archive, string $root, string $entry, string $edition, array $files): string|false
    {
        $selector = "if (\\Synergetic\\UnifiedShopExtras\\Edition\\prepare(__FILE__, '{$edition}')) {";
        $runtimeLoad = '~^' . preg_quote($selector, '~') . "\\R[ \\t]+require_once __DIR__ \\. '/uwe-runtime\\.php';~m";
        if (!isset($files['includes/edition-bootstrap.php']) || !preg_match($runtimeLoad, $entry)) {
            throw new InvalidArgumentException('Release ZIP entry point does not load the configured edition runtime.');
        }
        return $archive->getFromName($root . '/uwe-runtime.php', 512 * 1024);
    }

    private static function verifyBuildMarker(string|false $main, string $marker, string $edition): void
    {
        $pattern = '/^[ \t]*define\s*\(\s*([\'"])' . preg_quote($marker, '/') . '\1\s*,\s*([\'"])(lite|pro)\2\s*\)/m';
        if ($main === false || !preg_match($pattern, $main, $build) || $build[3] !== $edition) {
            throw new InvalidArgumentException('Release ZIP build marker does not match the configured edition.');
        }
    }
}
