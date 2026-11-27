<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\MainWP;

use MainWP\Dashboard\MainWP_DB;
use RuntimeException;
use Synergetic\MainWPProducts\Release\PublisherCatalog;
use Throwable;

final class Onboarding
{
    public const OPERATIONS = ['install_lite', 'install_pro', 'activate_lite', 'activate_pro', 'activate_license', 'save_override', 'clear_override'];

    public static function locked(string $scope, callable $operation): mixed
    {
        global $wpdb;
        $lock = 'syn-products-' . substr(hash('sha256', DB_NAME . ':' . $wpdb->options . ':' . $scope), 0, 45);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Serialize one site's operations.
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
            throw new RuntimeException('operation_busy');
        }
        try {
            return $operation();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the same connection lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function run(
        object $site,
        string $product,
        string $operation,
        string $key,
        ?array $expectedInventory = null,
        string|array|null $expectedSource = null
    ): string
    {
        $db = MainWP_DB::instance();
        $id = (int) $site->id;
        return self::locked('site-' . $id, static function () use ($site, $product, $operation, $key, $db, $id, $expectedInventory, $expectedSource): string {
            try {
                if ($operation === 'save_override' || $operation === 'clear_override') {
                    if ($operation === 'save_override' && $key === '') {
                        throw new RuntimeException('license_missing');
                    }
                    SavedLicenses::save($product, $id, $operation === 'save_override' ? $key : '');
                    return 'key_saved';
                }

                $inventory = self::currentInventory($site, $product);
                if ($expectedInventory !== null && $inventory !== $expectedInventory) {
                    return 'preview_stale';
                }
                if (!SiteInventory::permitsActions($product, $inventory, [], time()) || $inventory['pro']['state'] === 'legacy') {
                    throw new RuntimeException('operation_refused');
                }
                if ($operation === 'activate_license') {
                    self::activateLicense($site, $product, $inventory, $key, is_string($expectedSource) ? $expectedSource : null);
                    $outcome = 'license_activated';
                } else {
                    [$verb, $edition] = explode('_', $operation, 2);
                    self::changePlugin($site, $product, $verb, $edition, $inventory, is_array($expectedSource) ? $expectedSource : null);
                    $outcome = $verb === 'install' ? 'plugin_installed' : 'plugin_activated';
                }
            } catch (Throwable $error) {
                $outcome = in_array($error->getMessage(), self::RESULTS, true) ? $error->getMessage() : 'operation_failed';
            }

            $historyKey = self::historyKey($product);
            $cutoff = time() - 90 * DAY_IN_SECONDS;
            $history = array_filter($db->get_json_website_option($site, $historyKey, []), static fn ($entry): bool => ($entry['at'] ?? 0) >= $cutoff);
            $history[] = ['at' => time(), 'actor' => get_current_user_id(), 'operation' => $operation, 'result' => $outcome];
            $db->update_website_option($id, $historyKey, wp_json_encode(array_slice($history, -100)));
            return $outcome;
        });
    }

    private const RESULTS = [
        'license_missing', 'license_unreadable', 'license_invalid', 'license_storage', 'license_rejected',
        'license_unsupported', 'license_email', 'license_unconfirmed', 'operation_refused', 'operation_busy', 'operation_unconfirmed',
        'catalog_unavailable', 'pro_source_unavailable', 'lite_pending', 'lite_download_failed', 'package_storage', 'package_mismatch', 'preview_stale',
    ];

    public static function historyKey(string $product): string
    {
        return 'synergetic_products_operations_' . str_replace('-', '_', $product);
    }

    public static function statusKey(string $product): string
    {
        return 'synergetic_products_status_v1_' . str_replace('-', '_', $product);
    }

    public static function currentInventory(object $site, string $product, bool $persist = true): array
    {
        $plugins = ManagedConnection::call($site, 'get_all_plugins', ['filter' => '0'])['data'] ?? null;
        if (!is_array($plugins) || !array_is_list($plugins) || !array_filter($plugins, static fn ($p): bool => ($p['slug'] ?? '') === 'mainwp-child/mainwp-child.php')) {
            throw new RuntimeException('operation_unconfirmed');
        }
        $site = clone $site;
        $site->plugins = wp_json_encode($plugins);
        if ($persist) {
            MainWP_DB::instance()->update_website_values((int) $site->id, ['plugins' => $site->plugins]);
        }
        return SiteInventory::fromSite($site, $product);
    }

    private static function changePlugin(object $site, string $product, string $verb, string $edition, array $inventory, ?array $expectedPackage): void
    {
        $basename = PublisherCatalog::PRODUCTS[$product][$edition];
        $other = $edition === 'lite' ? 'pro' : 'lite';
        if ($verb === 'install') {
            if ($inventory[$edition]['state'] !== 'missing') {
                throw new RuntimeException('operation_refused');
            }
            $version = PackageDelivery::deliver($product, $edition, static function (string $url, string $approvedVersion) use ($site): string {
                ManagedConnection::call($site, 'installplugintheme', [
                    'type' => 'plugin', 'url' => wp_json_encode($url), 'activatePlugin' => 'no',
                ]);
                return $approvedVersion;
            }, $expectedPackage);
            $expected = 'inactive';
        } else {
            if ($inventory[$edition]['state'] !== 'inactive' || $inventory[$other]['state'] === 'active') {
                throw new RuntimeException('operation_refused');
            }
            ManagedConnection::call($site, 'plugin_action', ['action' => 'activate', 'plugin' => $basename]);
            $expected = 'active';
            $version = $inventory[$edition]['version'];
        }
        $observed = self::currentInventory($site, $product)[$edition];
        if ($observed['state'] !== $expected || $observed['version'] !== $version) {
            throw new RuntimeException('operation_unconfirmed');
        }
    }

    private static function activateLicense(object $site, string $product, array $inventory, string $key, ?string $expectedRevision): void
    {
        // @wp-test feature=single-site-onboarding scenario=license-readback
        if ($inventory['active_edition'] !== 'pro') {
            throw new RuntimeException('operation_refused');
        }
        $result = ManagedConnection::product($site, $product)['result'] ?? null;
        $before = SiteInventory::normalizeStatus($result, $product, $inventory, time());
        if ($before === null || ($result['capabilities']['license_activation'] ?? false) !== true) {
            throw new RuntimeException('license_unsupported');
        }
        if ($before['entitlement_active'] || ($before['woocommerce_eligible'] ?? true) === false) {
            throw new RuntimeException('operation_refused');
        }
        if (($result['administration_email_valid'] ?? false) !== true) {
            throw new RuntimeException('license_email');
        }
        $key = SavedLicenses::resolve($product, (int) $site->id, $key, $expectedRevision);
        $activation = ManagedConnection::product($site, $product, 'activate_license_v1', $key);
        $after = SiteInventory::normalizeStatus(ManagedConnection::product($site, $product)['result'] ?? null, $product, $inventory, time());
        if ($after !== null) {
            MainWP_DB::instance()->update_website_option((int) $site->id, self::statusKey($product), wp_json_encode($after + ['recorded_at' => microtime(true)]));
        }
        if (empty($after['entitlement_active'])) {
            $rejected = in_array($activation['code'] ?? '', ['uwe_mainwp_license_rejected', 'admin_suite_mainwp_license_rejected'], true);
            throw new RuntimeException($rejected ? 'license_rejected' : 'license_unconfirmed');
        }
    }
}
