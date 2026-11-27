<?php
declare(strict_types=1);

use Synergetic\MainWPProducts\Release\CatalogBuilder;
use Synergetic\MainWPProducts\Release\ProductPackage;
use Synergetic\MainWPProducts\Release\PublisherCatalog;

$bootstrap = __DIR__ . '/../src/Release/PublisherCatalog.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "Publisher catalog entry point is unavailable.\n");
    exit(1);
}
require $bootstrap;
require __DIR__ . '/../src/Release/ProductPackage.php';
require __DIR__ . '/../src/Release/CatalogBuilder.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function refuses(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $error) {
        check(str_contains($error->getMessage(), $message), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected refusal: ' . $message);
}

function fixtureZip(string $directory, string $name, string $basename, string $plugin, string $marker, string $edition, array $extra = [], ?string $runtimeEdition = null): array
{
    $zipPath = $directory . '/' . $name . '.zip';
    $root = explode('/', $basename, 2)[0];
    $entry = "<?php\n/*\n * Plugin Name: {$plugin}\n * Version: 1.2.3\n */\n";
    if ($runtimeEdition === null) {
        $entry .= "define('{$marker}', '{$edition}');\n";
    } else {
        $entry .= "if (\\Synergetic\\UnifiedShopExtras\\Edition\\prepare(__FILE__, '{$edition}')) {\n"
            . "    require_once __DIR__ . '/uwe-runtime.php';\n}\n";
        $extra += [
            $root . '/includes/edition-bootstrap.php' => '<?php',
            $root . '/uwe-runtime.php' => "<?php\ndefine('{$marker}', '{$runtimeEdition}');\n",
        ];
    }
    $files = [
        $basename => $entry,
        $root . '/includes/module.php' => '<?php return true;',
    ] + $extra;
    $zip = new ZipArchive();
    check($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) === true, 'Fixture could not be created.');
    foreach ($files as $path => $bytes) {
        check($zip->addFromString($path, $bytes), 'Fixture entry failed.');
    }
    check($zip->close(), 'Fixture could not be closed.');
    return ['file' => basename($zipPath), 'sha256' => hash_file('sha256', $zipPath)];
}

$temp = sys_get_temp_dir() . '/mainwp-catalog-' . bin2hex(random_bytes(8));
check(mkdir($temp, 0700), 'Fixture directory could not be created.');

try {
    $asLite = fixtureZip($temp, 'as-lite', 'admin-suite-lite/admin-suite-lite.php', 'Admin Suite Lite', 'SYNERGETIC_ADMIN_SUITE_BUILD_VARIANT', 'lite');
    $asPro = fixtureZip($temp, 'as-pro', 'admin-suite/admin-suite.php', 'Admin Suite', 'SYNERGETIC_ADMIN_SUITE_BUILD_VARIANT', 'pro');
    $uweLite = fixtureZip($temp, 'uwe-lite', 'unified-shop-extras-lite/unified-shop-extras-lite.php', 'Unified Shop Extras Lite', 'UWE_BUILD_VARIANT', 'lite', runtimeEdition: 'lite');
    $uwePro = fixtureZip($temp, 'uwe-pro', 'unified-shop-extras/unified-shop-extras.php', 'Unified Shop Extras Pro', 'UWE_BUILD_VARIANT', 'pro', runtimeEdition: 'pro');
    $mismatched = fixtureZip($temp, 'uwe-mixed-runtime', 'unified-shop-extras/unified-shop-extras.php', 'Unified Shop Extras Pro', 'UWE_BUILD_VARIANT', 'pro', runtimeEdition: 'lite');
    $legacy = fixtureZip($temp, 'legacy-uwe', 'unified-shop-extras/unified-shop-extras.php', 'Unified Shop Extras', 'UWE_BUILD_VARIANT', 'lite');
    $traversal = fixtureZip($temp, 'unsafe', 'admin-suite/admin-suite.php', 'Admin Suite', 'SYNERGETIC_ADMIN_SUITE_BUILD_VARIANT', 'pro', ['admin-suite/../extra.php' => 'unsafe']);

    $configuration = [
        'revision' => 42,
        'products' => [
            'admin-suite' => ['lite_version' => '1.2.3', 'releases' => ['1.2.3' => [
                'lite' => $asLite + ['url' => 'https://downloads.example.test/admin-suite-lite-1.2.3.zip'],
                'pro' => $asPro,
            ]]],
            'unified-shop-extras' => ['lite_version' => '1.2.3', 'releases' => ['1.2.3' => [
                'lite' => $uweLite + ['url' => 'https://downloads.example.test/unified-shop-extras-lite-1.2.3.zip'],
                'pro' => $uwePro,
            ]]],
        ],
    ];
    $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
    $pin = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
    $document = CatalogBuilder::build($configuration, $temp, $seed);
    $catalog = PublisherCatalog::verify($document, $pin, 42);
    $proOnly = $configuration;
    foreach ($proOnly['products'] as &$product) {
        $product['lite_version'] = null;
        unset($product['releases']['1.2.3']['lite']);
    }
    unset($product);
    $withoutLite = PublisherCatalog::verify(CatalogBuilder::build($proOnly, $temp, $seed), $pin, 42);
    check($withoutLite->litePackage('admin-suite') === null && $withoutLite->litePackage('unified-shop-extras') === null, 'Unpublished Lite packages were offered.');
    check($withoutLite->proManifest('unified-shop-extras', '1.2.3')['sha256'] === $uwePro['sha256'], 'Disabling Lite blocked the Pro manifest.');
    $proOnly['products']['admin-suite']['lite_version'] = '1.2.3';
    refuses(fn () => CatalogBuilder::build($proOnly, $temp, $seed), 'no approved package');
    check($catalog->revision() === 42, 'Catalog revision was lost.');
    check($catalog->litePackage('admin-suite')['sha256'] === $asLite['sha256'], 'Lite archive digest mismatch.');
    $manifest = $catalog->proManifest('unified-shop-extras', '1.2.3');
    check($manifest['files']['includes/module.php']['sha256'] === hash('sha256', '<?php return true;'), 'Pro file manifest mismatch.');
    $tampered = json_decode($document, true, flags: JSON_THROW_ON_ERROR);
    $payload = base64_decode($tampered['payload'], true);
    check(!str_contains($payload, $temp) && !str_contains($payload, '"file"'), 'Publisher path escaped into public catalog.');
    check(!array_key_exists('url', $manifest), 'Private package URL was published.');

    $tampered['payload'] = base64_encode(str_replace('downloads.example.test', 'altered.example.test', $payload));
    refuses(fn () => PublisherCatalog::verify(json_encode($tampered), $pin), 'signature');
    refuses(fn () => PublisherCatalog::verify($document, str_repeat('x', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)), 'signature');
    refuses(fn () => PublisherCatalog::verify($document, $pin, 43), 'revision');
    refuses(fn () => $catalog->proManifest('unified-shop-extras', '1.2.4'), 'version');
    refuses(fn () => ProductPackage::inspect($temp . '/' . $legacy['file'], 'unified-shop-extras', 'lite', '1.2.3', $legacy['sha256']), 'basename');
    refuses(fn () => ProductPackage::inspect($temp . '/' . $legacy['file'], 'unified-shop-extras', 'pro', '1.2.3', $legacy['sha256']), 'edition');
    refuses(fn () => ProductPackage::inspect($temp . '/' . $mismatched['file'], 'unified-shop-extras', 'pro', '1.2.3', $mismatched['sha256']), 'edition');
    refuses(fn () => ProductPackage::inspect($temp . '/' . $uwePro['file'], 'unified-shop-extras', 'pro', '1.2.3', $asPro['sha256']), 'digest');
    refuses(fn () => ProductPackage::inspect($temp . '/' . $traversal['file'], 'admin-suite', 'pro', '1.2.3', $traversal['sha256']), 'path');

    $insecureUrl = $configuration;
    $insecureUrl['products']['admin-suite']['releases']['1.2.3']['lite']['url'] = 'http://downloads.example.test/admin-suite.zip';
    refuses(fn () => CatalogBuilder::build($insecureUrl, $temp, $seed), 'HTTPS');

    $tree = $temp . '/unified-shop-extras';
    check(mkdir($tree . '/includes', 0700, true), 'Installed fixture directory failed.');
    $zip = new ZipArchive();
    check($zip->open($temp . '/' . $uwePro['file']) === true, 'Installed fixture ZIP open failed.');
    foreach ($manifest['files'] as $path => $entry) {
        file_put_contents($tree . '/' . $path, $zip->getFromName('unified-shop-extras/' . $path));
    }
    $zip->close();
    ProductPackage::verifyInstalled($tree, $manifest);
    file_put_contents($tree . '/includes/module.php', '<?php return false;');
    refuses(fn () => ProductPackage::verifyInstalled($tree, $manifest), 'installed file');
    file_put_contents($tree . '/includes/module.php', '<?php return true;');
    file_put_contents($tree . '/unexpected.php', '<?php');
    refuses(fn () => ProductPackage::verifyInstalled($tree, $manifest), 'unexpected');

    echo "Publisher catalog contract passed.\n";
} finally {
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($paths as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    rmdir($temp);
}
