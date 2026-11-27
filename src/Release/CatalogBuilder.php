<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\Release;

use InvalidArgumentException;

final class CatalogBuilder
{
    public static function build(array $configuration, string $baseDirectory, string $seed): string
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair') || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new InvalidArgumentException('Catalog signing requires Sodium and a 32-byte Ed25519 seed.');
        }
        $revision = $configuration['revision'] ?? null;
        $products = $configuration['products'] ?? null;
        if (!is_int($revision) || $revision < 1 || !is_array($products) || count($products) !== count(PublisherCatalog::PRODUCTS)) {
            throw new InvalidArgumentException('Supply a positive catalog revision and both products.');
        }
        $catalog = ['schema' => PublisherCatalog::SCHEMA, 'revision' => $revision, 'products' => []];
        foreach (array_keys(PublisherCatalog::PRODUCTS) as $product) {
            $configured = $products[$product] ?? null;
            if (!is_array($configured) || !array_key_exists('lite_version', $configured)
                || !is_array($configured['releases'] ?? null) || $configured['releases'] === []) {
                throw new InvalidArgumentException('Supply releases and a selected Lite version for ' . $product . '.');
            }
            $releases = $configured['releases'];
            ksort($releases, SORT_STRING);
            $approved = [];
            foreach ($releases as $version => $release) {
                if (!PublisherCatalog::validVersion($version) || !is_array($release)) {
                    throw new InvalidArgumentException('Invalid release entry for ' . $product . '.');
                }
                foreach (['lite', 'pro'] as $edition) {
                    if ($edition === 'lite' && !array_key_exists('lite', $release)) {
                        continue;
                    }
                    try {
                        $approved[$version][$edition] = self::inspectRelease($baseDirectory, $product, $edition, $version, $release[$edition] ?? null);
                    } catch (InvalidArgumentException $error) {
                        throw new InvalidArgumentException("{$product} {$edition} {$version}: " . $error->getMessage(), 0, $error);
                    }
                }
            }
            $catalog['products'][$product] = ['lite_version' => $configured['lite_version'], 'releases' => $approved];
        }

        $payload = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $keys = sodium_crypto_sign_seed_keypair($seed);
        $secret = sodium_crypto_sign_secretkey($keys);
        try {
            $signature = sodium_crypto_sign_detached(PublisherCatalog::SIGNATURE_CONTEXT . $payload, $secret);
            $document = json_encode(['payload' => base64_encode($payload), 'signature' => base64_encode($signature)], JSON_THROW_ON_ERROR) . "\n";
            PublisherCatalog::verify($document, sodium_crypto_sign_publickey($keys), $revision);
            return $document;
        } finally {
            sodium_memzero($secret);
            sodium_memzero($keys);
        }
    }

    private static function inspectRelease(string $baseDirectory, string $product, string $edition, string $version, mixed $entry): array
    {
        if (!is_array($entry) || !is_string($entry['file'] ?? null) || $entry['file'] === ''
            || !PublisherCatalog::validDigest($entry['sha256'] ?? null)) {
            throw new InvalidArgumentException('Supply a local ZIP path and its approved SHA-256 digest.');
        }
        $file = $entry['file'];
        if (preg_match('~\A(?:[A-Za-z]:)?[/\\\\]~', $file) !== 1) {
            $file = $baseDirectory . DIRECTORY_SEPARATOR . $file;
        }
        $approved = ProductPackage::inspect($file, $product, $edition, $version, $entry['sha256']);
        if ($edition === 'lite') {
            unset($approved['files']);
            $approved['url'] = $entry['url'] ?? null;
        }
        return $approved;
    }
}
