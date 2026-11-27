<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\MainWP;

use RuntimeException;
use InvalidArgumentException;
use Synergetic\MainWPProducts\Release\ProductPackage;
use Synergetic\MainWPProducts\Release\PublisherCatalog;
use ZipArchive;

final class PackageDelivery
{
    public const ACTION = 'synergetic_mainwp_product_zip';
    private const PREFIX = 'syn_mwp_zip_';
    private const TTL = 180;

    public static function configured(): bool
    {
        return defined('SYNERGETIC_MAINWP_CATALOG_FILE') && defined('SYNERGETIC_MAINWP_PUBLISHER_PIN')
            && class_exists(ZipArchive::class) && function_exists('sodium_crypto_sign_verify_detached')
            && wp_parse_url(admin_url('admin-post.php'), PHP_URL_SCHEME) === 'https';
    }

    public static function catalog(): ?PublisherCatalog
    {
        if (!self::configured() || !is_string(SYNERGETIC_MAINWP_PUBLISHER_PIN) || !is_string(SYNERGETIC_MAINWP_CATALOG_FILE)) {
            return null;
        }
        $pin = base64_decode(SYNERGETIC_MAINWP_PUBLISHER_PIN, true);
        $document = is_readable(SYNERGETIC_MAINWP_CATALOG_FILE)
            ? file_get_contents(SYNERGETIC_MAINWP_CATALOG_FILE, false, null, 0, PublisherCatalog::MAX_DOCUMENT_BYTES + 1) : false;
        $minimum = defined('SYNERGETIC_MAINWP_MIN_CATALOG_REVISION') ? SYNERGETIC_MAINWP_MIN_CATALOG_REVISION : 1;
        if ($document === false || $pin === false || !is_int($minimum)) {
            return null;
        }
        try {
            return PublisherCatalog::verify($document, $pin, $minimum);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function proSource(string $product): ?array
    {
        $basename = PublisherCatalog::PRODUCTS[$product]['pro'];
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (!is_plugin_active($basename) || !is_readable(WP_PLUGIN_DIR . '/' . $basename)) {
            return null;
        }
        if ($product === SiteInventory::SHOP) {
            if (!defined('UWE_PLUGIN_FILE') || plugin_basename(UWE_PLUGIN_FILE) !== $basename
                || !defined('UWE_BUILD_VARIANT') || UWE_BUILD_VARIANT !== 'pro'
                || !defined('UWE_DISTRIBUTION_BUILD') || !UWE_DISTRIBUTION_BUILD
                || !defined('WC_VERSION') || version_compare(WC_VERSION, SiteInventory::WOO_MINIMUM, '<')
                || !is_plugin_active('woocommerce/woocommerce.php') || !class_exists('WooCommerce', false)
                || !function_exists('uwe_lic_has_active_entitlement') || !uwe_lic_has_active_entitlement()
                || uwe_lic_is_revocation_pending() || (uwe_lic_get_status()['state'] ?? '') !== 'valid'
                || !is_callable(['Unified_W_C_Extras_Pro_Base', 'get_register_info'])) {
                return null;
            }
            $registration = \Unified_W_C_Extras_Pro_Base::get_register_info(UWE_PLUGIN_FILE);
        } else {
            if (!defined('SYNERGETIC_ADMIN_SUITE_PLUGIN_FILE') || plugin_basename(SYNERGETIC_ADMIN_SUITE_PLUGIN_FILE) !== $basename
                || !function_exists('synergetic_admin_suite_license_private_updates_enabled')
                || !synergetic_admin_suite_license_private_updates_enabled()
                || (synergetic_admin_suite_license_get_status()['state'] ?? '') !== 'valid'
                || !is_callable(['Admin_Suite_Pro_Base', 'get_register_info'])) {
                return null;
            }
            $registration = \Admin_Suite_Pro_Base::get_register_info(SYNERGETIC_ADMIN_SUITE_PLUGIN_FILE);
        }
        if (!is_object($registration) || empty($registration->is_valid) || empty($registration->license_key)) {
            return null;
        }
        $expiry = strtolower(trim((string) ($registration->expire_date ?? '')));
        if (!in_array($expiry, ['unlimited', 'no expiry'], true)
            && ($expiry === '' || strtotime($expiry) <= time())) {
            return null;
        }
        $version = get_file_data(WP_PLUGIN_DIR . '/' . $basename, ['version' => 'Version'])['version'];
        return ['directory' => WP_PLUGIN_DIR . '/' . dirname($basename), 'version' => $version];
    }

    /** Give the native installer a temporary HTTPS URL for an already verified ZIP. */
    public static function deliver(string $product, string $edition, callable $install, ?array $expectedPackage = null): mixed
    {
        $catalog = self::catalog() ?? throw new RuntimeException('catalog_unavailable');
        $temp = realpath(sys_get_temp_dir());
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compare the server's filesystem path as written.
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) ? wp_unslash($_SERVER['DOCUMENT_ROOT']) : '';
        if ($temp === false || self::inside($temp, ABSPATH) || self::inside($temp, WP_CONTENT_DIR)
            || ($documentRoot !== '' && self::inside($temp, $documentRoot))) {
            throw new RuntimeException('package_storage');
        }
        $file = tempnam($temp, 'syn-mainwp-');
        if ($file === false) {
            throw new RuntimeException('package_storage');
        }
        $handle = self::PREFIX . hash('sha256', $token = bin2hex(random_bytes(32)));
        try {
            $version = self::build($catalog, $product, $edition, $file, $expectedPackage);
            $hash = hash_file('sha256', $file);
            if (!is_string($hash) || !set_transient($handle, ['file' => $file, 'hash' => $hash], self::TTL)) {
                throw new RuntimeException('package_storage');
            }
            $url = add_query_arg(['action' => self::ACTION, 'token' => $token], admin_url('admin-post.php'));
            return $install($url, $version);
        } finally {
            delete_transient($handle);
            wp_delete_file($file);
        }
    }

    public static function download(): never
    {
        if (!is_ssl()) {
            status_header(404);
            exit;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The URL has a high-entropy bearer token, validated exactly below.
        $token = isset($_GET['token']) && is_string($_GET['token']) ? wp_unslash($_GET['token']) : '';
        $record = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1 ? get_transient(self::PREFIX . hash('sha256', $token)) : false;
        $file = is_array($record) ? ($record['file'] ?? '') : '';
        $stream = is_string($file) && is_file($file) && !is_link($file) ? fopen($file, 'rb') : false;
        if (!is_resource($stream)) {
            status_header(404);
            exit;
        }
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        if (!is_string($record['hash'] ?? null) || !hash_equals($record['hash'], hash_final($hash))) {
            fclose($stream);
            status_header(404);
            exit;
        }
        rewind($stream);
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="product.zip"');
        header('Content-Length: ' . fstat($stream)['size']);
        header('Referrer-Policy: no-referrer');
        fpassthru($stream);
        fclose($stream);
        exit;
    }

    private static function build(PublisherCatalog $catalog, string $product, string $edition, string $file, ?array $expectedPackage): string
    {
        if ($edition === 'lite') {
            $package = $catalog->litePackage($product) ?? throw new RuntimeException('lite_pending');
            self::requireExpectedPackage($catalog, $package, $expectedPackage);
            $reply = wp_safe_remote_get($package['url'], [
                'stream' => true, 'filename' => $file, 'timeout' => 30, 'sslverify' => true,
                'redirection' => 0, 'limit_response_size' => $package['bytes'] + 1,
            ]);
            if (is_wp_error($reply) || wp_remote_retrieve_response_code($reply) !== 200) {
                throw new RuntimeException('lite_download_failed');
            }
            ProductPackage::inspect($file, $product, 'lite', $package['version'], $package['sha256']);
            return $package['version'];
        }

        $source = self::proSource($product);
        if ($source === null) {
            throw new RuntimeException('pro_source_unavailable');
        }
        $manifest = $catalog->proManifest($product, $source['version']);
        self::requireExpectedPackage($catalog, $manifest, $expectedPackage);
        ProductPackage::verifyInstalled($source['directory'], $manifest);
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('package_storage');
        }
        try {
            foreach ($manifest['files'] as $relative => $expected) {
                $sourceFile = $source['directory'] . '/' . $relative;
                $bytes = file_get_contents($sourceFile, false, null, 0, $expected['bytes'] + 1);
                if ($bytes === false || strlen($bytes) !== $expected['bytes'] || !hash_equals($expected['sha256'], hash('sha256', $bytes))
                    || !$zip->addFromString(basename($source['directory']) . '/' . $relative, $bytes)) {
                    throw new RuntimeException('package_mismatch');
                }
            }
        } finally {
            $closed = $zip->close();
        }
        if (!$closed) {
            throw new RuntimeException('package_storage');
        }
        $digest = hash_file('sha256', $file);
        $built = ProductPackage::inspect($file, $product, 'pro', $source['version'], $digest);
        if ($built['files'] !== $manifest['files'] || self::proSource($product) !== $source) {
            throw new RuntimeException('package_mismatch');
        }
        return $source['version'];
    }

    public static function packageIdentity(PublisherCatalog $catalog, array $package): array
    {
        return [$catalog->revision(), $package['version'], hash('sha256', wp_json_encode($package))];
    }

    private static function requireExpectedPackage(PublisherCatalog $catalog, array $package, ?array $expected): void
    {
        if ($expected !== null && self::packageIdentity($catalog, $package) !== $expected) {
            throw new RuntimeException('preview_stale');
        }
    }

    private static function inside(string $path, string $directory): bool
    {
        $root = realpath($directory);
        return $root !== false && str_starts_with(str_replace('\\', '/', $path . '/'), rtrim(str_replace('\\', '/', $root), '/') . '/');
    }
}
