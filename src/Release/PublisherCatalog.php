<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\Release;

use InvalidArgumentException;
use JsonException;

final class PublisherCatalog
{
    public const SCHEMA = 1;
    public const MAX_DOCUMENT_BYTES = 16 * 1024 * 1024;
    public const MAX_ZIP_BYTES = 64 * 1024 * 1024;
    public const MAX_TREE_BYTES = 256 * 1024 * 1024;
    public const MAX_FILES = 10000;
    public const SIGNATURE_CONTEXT = "synergetic-mainwp-products-catalog/v1\0";
    public const PRODUCTS = [
        'admin-suite' => [
            'lite' => 'admin-suite-lite/admin-suite-lite.php',
            'pro' => 'admin-suite/admin-suite.php',
            'marker' => 'SYNERGETIC_ADMIN_SUITE_BUILD_VARIANT',
        ],
        'unified-shop-extras' => [
            'lite' => 'unified-shop-extras-lite/unified-shop-extras-lite.php',
            'pro' => 'unified-shop-extras/unified-shop-extras.php',
            'marker' => 'UWE_BUILD_VARIANT',
        ],
    ];

    private function __construct(private readonly array $catalog)
    {
    }

    public static function verify(string $document, string $publisherKey, int $minimumRevision = 1): self
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new InvalidArgumentException('The Sodium extension is required to verify the catalog.');
        }
        if (strlen($publisherKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $minimumRevision < 1) {
            throw new InvalidArgumentException('Invalid publisher verification key or minimum revision.');
        }
        if (strlen($document) > self::MAX_DOCUMENT_BYTES) {
            throw new InvalidArgumentException('Catalog document exceeds the size limit.');
        }

        $envelope = self::decodeObject($document);
        if (count($envelope) !== 2 || !is_string($envelope['payload'] ?? null) || !is_string($envelope['signature'] ?? null)) {
            throw new InvalidArgumentException('Invalid signed catalog envelope.');
        }
        $payload = base64_decode($envelope['payload'], true);
        $signature = base64_decode($envelope['signature'], true);
        if ($payload === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, self::SIGNATURE_CONTEXT . $payload, $publisherKey)) {
            throw new InvalidArgumentException('Catalog signature verification failed.');
        }

        $catalog = self::decodeObject($payload);
        if (($catalog['schema'] ?? null) !== self::SCHEMA || !is_int($catalog['revision'] ?? null)
            || $catalog['revision'] < $minimumRevision || !is_array($catalog['products'] ?? null)
            || count($catalog['products']) !== count(self::PRODUCTS)) {
            throw new InvalidArgumentException('Unsupported catalog schema, revision or product list.');
        }
        foreach (self::PRODUCTS as $product => $identity) {
            self::validateProduct($catalog['products'][$product] ?? null, $identity);
        }

        return new self($catalog);
    }

    public function revision(): int
    {
        return $this->catalog['revision'];
    }

    public function litePackage(string $product): ?array
    {
        $record = $this->product($product);
        $version = $record['lite_version'];
        return $version === null ? null : ['version' => $version] + $record['releases'][$version]['lite'];
    }

    public function proManifest(string $product, string $version): array
    {
        $release = $this->product($product)['releases'][$version] ?? null;
        if ($release === null) {
            throw new InvalidArgumentException('No approved manifest for the installed Pro version.');
        }
        return ['version' => $version] + $release['pro'];
    }

    public static function validVersion(mixed $version): bool
    {
        return is_string($version) && preg_match('/\A[0-9]+(?:\.[0-9]+){1,3}(?:[-+][a-zA-Z0-9.-]+)?\z/', $version) === 1
            && strlen($version) <= 64;
    }

    public static function validDigest(mixed $digest): bool
    {
        return is_string($digest) && preg_match('/\A[a-f0-9]{64}\z/', $digest) === 1;
    }

    public static function validPath(string $path): bool
    {
        if (strlen($path) > 512 || preg_match('/[^\x20-\x7e]/', $path) === 1 || strpbrk($path, '\\:<>|*?"') !== false) {
            return false;
        }
        foreach (explode('/', $path) as $component) {
            if ($component === '' || strlen($component) > 255 || $component !== rtrim($component, '. ')
                || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/i', $component) === 1) {
                return false;
            }
        }
        return true;
    }

    public static function decodeObject(string $json): array
    {
        try {
            $object = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Invalid catalog JSON.', 0, $error);
        }
        if (!is_array($object) || array_is_list($object)) {
            throw new InvalidArgumentException('Expected a JSON object.');
        }
        return $object;
    }

    private function product(string $product): array
    {
        return $this->catalog['products'][$product] ?? throw new InvalidArgumentException('Unknown catalog product.');
    }

    private static function validateProduct(mixed $product, array $identity): void
    {
        if (!is_array($product) || !array_key_exists('lite_version', $product) || !is_array($product['releases'] ?? null)
            || $product['releases'] === []) {
            throw new InvalidArgumentException('Invalid product release list or Lite version.');
        }
        $lite = $product['lite_version'];
        if ($lite !== null && (!self::validVersion($lite) || !isset($product['releases'][$lite]['lite']))) {
            throw new InvalidArgumentException('The selected Lite version has no approved package.');
        }
        foreach ($product['releases'] as $version => $release) {
            if (!self::validVersion($version) || !is_array($release)) {
                throw new InvalidArgumentException('Invalid product release version.');
            }
            if (array_key_exists('lite', $release)) {
                self::validatePackage($release['lite'], $identity['lite'], false);
            }
            self::validatePackage($release['pro'] ?? null, $identity['pro'], true);
        }
    }

    private static function validatePackage(mixed $package, string $basename, bool $pro): void
    {
        if (!is_array($package) || count($package) !== 4 || ($package['basename'] ?? null) !== $basename
            || !self::validDigest($package['sha256'] ?? null) || !is_int($package['bytes'] ?? null)
            || $package['bytes'] <= 0 || $package['bytes'] > self::MAX_ZIP_BYTES) {
            throw new InvalidArgumentException('Invalid signed package metadata.');
        }
        if (!$pro) {
            self::validateUrl($package['url'] ?? null);
            return;
        }
        [, $mainFile] = explode('/', $basename, 2);
        $files = $package['files'] ?? null;
        if (!is_array($files) || !isset($files[$mainFile]) || count($files) > self::MAX_FILES) {
            throw new InvalidArgumentException('Invalid Pro release manifest.');
        }
        $total = 0;
        $paths = [];
        foreach ($files as $path => $file) {
            if (!is_string($path) || !self::validPath($path) || !is_array($file) || count($file) !== 2
                || !self::validDigest($file['sha256'] ?? null) || !is_int($file['bytes'] ?? null)
                || $file['bytes'] < 0 || $file['bytes'] > self::MAX_TREE_BYTES) {
                throw new InvalidArgumentException('Invalid Pro manifest file entry.');
            }
            $total += $file['bytes'];
            $folded = strtolower($path);
            if ($total > self::MAX_TREE_BYTES || isset($paths[$folded])) {
                throw new InvalidArgumentException('Pro manifest has excessive bytes or duplicate file paths.');
            }
            $paths[$folded] = true;
        }
    }

    private static function validateUrl(mixed $url): void
    {
        $parts = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : false;
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || ($parts['port'] ?? 443) !== 443 || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Lite packages require an approved HTTPS URL.');
        }
    }
}
