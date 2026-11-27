<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\MainWP;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_System_Utility;
use Synergetic\MainWPProducts\Release\PublisherCatalog;
use Throwable;
use WP_Error;

final class Abilities
{
    public const SLUG = 'synergetic-mainwp-products';
    private const OPERATIONS = ['install_lite', 'install_pro', 'activate_lite', 'activate_pro', 'activate_license'];
    private const SETTINGS = ['admin-suite' => 'enable_row_actions', SiteInventory::SHOP => 'sale_price_polish'];
    private const PLAN_PREFIX = 'syn_product_plan_';
    private const PLAN_TTL = 300;

    public static function boot(string $file): void
    {
        add_filter('mainwp_mcp_bridge_ability_namespaces', static function (array $namespaces): array {
            $namespaces[] = self::SLUG;
            return array_values(array_unique($namespaces));
        });
        $access = static function () use ($file): bool|WP_Error {
            return current_user_can('manage_options') && \mainwp_current_user_can('extension', dirname(plugin_basename($file)))
                ? true : new WP_Error('product_access_denied', 'MainWP extension administrator access is required.', ['status' => 403]);
        };
        add_action('wp_abilities_api_categories_init', static function (): void {
            wp_register_ability_category(self::SLUG, [
                'label' => 'Synergetic MainWP products',
                'description' => 'Manage Admin Suite and Unified Shop Extras on connected sites.',
            ]);
        });
        add_action('wp_abilities_api_init', static function () use ($access): void {
            self::register($access);
        });
    }

    private static function register(callable $access): void
    {
        $product = ['type' => 'string', 'enum' => array_keys(PublisherCatalog::PRODUCTS)];
        $site = ['site_id' => ['type' => 'integer', 'minimum' => 1], 'product' => $product];
        $preview = [
            'dry_run' => ['type' => 'boolean', 'description' => 'Preview the change without applying it.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Set only after the operator approves the preview.'],
            'preview_token' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'The add-on token from the dry run.'],
        ];
        $tools = [
            'list-sites' => ['List product sites', 'List visible MainWP site IDs and Lite, Pro, and cached license status for one product.',
                ['product' => $product, 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000]],
                ['product'], [self::class, 'listSites'], false],
            'get-status' => ['Get product status', 'Read synced edition inventory and the latest supported signed license status for one site.',
                $site, array_keys($site), [self::class, 'status'], false],
            'onboard-site' => ['Onboard a product on one site', 'Preview or perform one installation, plugin activation, or Pro license activation using a saved Dashboard key. Start with dry_run. License keys cannot be passed to this tool.',
                $site + ['operation' => ['type' => 'string', 'enum' => self::OPERATIONS]] + $preview,
                ['site_id', 'product', 'operation'], [self::class, 'onboard'], true],
            'get-setting' => ['Get supported product setting', 'Read the portable Content Duplicator or Product Presentation setting supported by the connected product.',
                $site, array_keys($site), [self::class, 'getSetting'], false],
            'update-setting' => ['Update supported product setting', 'Preview and update the single supported setting on one site. Start with dry_run and reuse its preview_token after approval.',
                $site + ['value' => ['type' => 'boolean']] + $preview,
                ['site_id', 'product', 'value'], [self::class, 'updateSetting'], true],
        ];
        // @wp-test feature=product-mcp-abilities scenario=discover-and-read
        foreach ($tools as $slug => [$label, $description, $properties, $required, $handler, $write]) {
            wp_register_ability(self::SLUG . '/' . $slug, [
                'label' => $label, 'description' => $description, 'category' => self::SLUG,
                'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
                'output_schema' => ['type' => 'object'],
                'permission_callback' => $access, 'execute_callback' => $handler,
                'meta' => [
                    'show_in_rest' => false,
                    'annotations' => ['readonly' => !$write, 'destructive' => $write, 'idempotent' => !$write],
                ],
            ]);
        }
    }

    public static function listSites(array $input): array
    {
        $page = $input['page'] ?? 1;
        $rows = MainWP_DB::instance()->get_websites_for_current_user([
            'select_data' => ['id', 'name', 'url', 'plugins', 'dtsSync', 'sync_errors', 'suspended', 'userid'],
            'extra_view' => [], 'format' => 'array', 'offset' => ($page - 1) * 25, 'rowcount' => 26,
        ]);
        return [
            'page' => $page, 'has_more' => count($rows) > 25,
            'sites' => array_map(static fn (object $site): array => self::describe($site, $input['product']), array_slice($rows, 0, 25)),
        ];
    }

    public static function status(array $input): array|WP_Error
    {
        $site = self::site($input['site_id']);
        if (is_wp_error($site)) {
            return $site;
        }
        $product = $input['product'];
        $status = self::describe($site, $product);
        if ($status['inventory']['connected'] && ManagedConnection::ready($site) && $status['inventory']['active_edition'] !== '') {
            $reply = ManagedConnection::product($site, $product);
            $verified = SiteInventory::normalizeStatus($reply['result'] ?? null, $product, $status['inventory'], time());
            if ($verified !== null) {
                $status['license'] = $verified['edition'] === 'pro' ? $verified : null;
                $status['license_source'] = $verified['edition'] === 'pro' ? 'live' : 'not_applicable';
                $status['child_contract'] = 'supported';
                if ($product === SiteInventory::SHOP) {
                    $status['woocommerce_eligibility'] = SiteInventory::wooEligibility($status['inventory'], $verified, (int) $site->dtsSync);
                }
            } elseif (($reply['outcome'] ?? '') === 'unsupported') {
                $status['child_contract'] = 'unsupported';
            }
        }
        return $status;
    }

    public static function onboard(array $input): array|WP_Error
    {
        // @wp-test feature=product-mcp-abilities scenario=confirmed-onboarding
        $site = self::site($input['site_id'], true);
        if (is_wp_error($site)) {
            return $site;
        }
        $product = $input['product'];
        $operation = $input['operation'];
        $capability = str_starts_with($operation, 'install_') ? 'install_plugins' : 'activate_deactivate_plugins';
        if (!\mainwp_current_user_can('dashboard', $capability)) {
            return new WP_Error('product_access_denied', 'MainWP does not permit this operation.', ['status' => 403]);
        }
        try {
            $inventory = self::liveInventory($site, $product);
            $source = self::operationSource($site, $product, $operation, $inventory);
            $plan = ['action' => $operation, 'target' => self::target($site, $product), 'url' => $site->url,
                'inventory' => $inventory, 'source' => $source['binding']];
            return self::review($input, $plan, [
                'target' => $plan['target'], 'operation' => $operation,
                'inventory' => $inventory, 'package_version' => $source['version'],
                'credential' => $operation === 'activate_license' ? 'dashboard_saved_key' : null,
            ], static function () use ($site, $product, $operation, $inventory, $source): array|WP_Error {
                $result = Onboarding::run($site, $product, $operation, '', $inventory, $source['binding']);
                return in_array($result, ['plugin_installed', 'plugin_activated', 'license_activated'], true)
                    ? ['outcome' => $result, 'target' => self::target($site, $product)]
                    : new WP_Error($result, 'The operation was not confirmed. Read site status before preparing another change.');
            });
        } catch (Throwable) {
            return new WP_Error('product_operation_unavailable', 'The current site, package, or saved license state does not allow this operation.');
        }
    }

    public static function getSetting(array $input): array|WP_Error
    {
        $site = self::site($input['site_id'], true);
        if (is_wp_error($site)) {
            return $site;
        }
        try {
            return self::readSetting($site, $input['product']);
        } catch (Throwable) {
            return new WP_Error('product_setting_unavailable', 'The child does not report this setting. Check its product build, module, and eligibility.');
        }
    }

    public static function updateSetting(array $input): array|WP_Error
    {
        // @wp-test feature=product-mcp-abilities scenario=confirmed-setting
        $site = self::site($input['site_id'], true);
        if (is_wp_error($site)) {
            return $site;
        }
        $product = $input['product'];
        $value = $input['value'];
        try {
            $before = self::readSetting($site, $product);
            $plan = ['action' => 'update_setting', 'target' => $before['target'], 'url' => $site->url,
                'checksum' => $before['checksum'], 'value' => $value];
            return self::review($input, $plan, [
                'target' => $before['target'], 'field' => $before['field'], 'current_value' => $before['value'], 'new_value' => $value,
            ], static function () use ($site, $product, $value, $before): array|WP_Error {
                return Onboarding::locked('site-' . (int) $site->id, static function () use ($site, $product, $value, $before): array|WP_Error {
                    if (self::readSetting($site, $product) !== $before) {
                        return new WP_Error('product_preview_stale', 'The setting changed. Request a new preview.');
                    }
                    if ($value === $before['value']) {
                        return ['outcome' => 'unchanged'] + $before;
                    }
                    $applied = ManagedConnection::settings($site, $product, $value, $before['checksum']);
                    if (($applied['ok'] ?? null) !== true) {
                        return new WP_Error('product_setting_unconfirmed', 'The child refused the setting change or could not confirm it. Read it again before retrying.');
                    }
                    $after = self::readSetting($site, $product);
                    return $after['value'] === $value ? ['outcome' => 'updated'] + $after
                        : new WP_Error('product_setting_unconfirmed', 'A fresh read did not confirm the requested value.');
                });
            });
        } catch (Throwable) {
            return new WP_Error('product_setting_unavailable', 'The setting could not be verified. Read it again before retrying.');
        }
    }

    private static function site(int $id, bool $edit = false): object
    {
        $site = MainWP_DB::instance()->get_website_by_id($id);
        if (!is_object($site) || ($edit && !MainWP_System_Utility::can_edit_website($site))) {
            return new WP_Error('product_site_unavailable', 'The requested site is outside your MainWP access.', ['status' => 403]);
        }
        return $site;
    }

    private static function describe(object $site, string $product): array
    {
        $inventory = SiteInventory::fromSite($site, $product);
        $value = MainWP_DB::instance()->get_json_website_option($site, Onboarding::statusKey($product), []);
        $license = null;
        if ($inventory['active_edition'] === 'pro' && ($value['edition'] ?? '') === 'pro' && ($value['version'] ?? '') === $inventory['pro']['version']) {
            $license = array_intersect_key($value, array_flip(['edition', 'version', 'checked_at', 'entitlement_active', 'updates_allowed', 'license_state']));
        }
        return [
            'target' => self::target($site, $product), 'inventory' => $inventory,
            'woocommerce_eligibility' => $product === SiteInventory::SHOP ? SiteInventory::wooEligibility($inventory, $value, (int) ($site->dtsSync ?? 0)) : null,
            'license' => $license, 'license_source' => $license === null ? 'unavailable' : 'cached',
        ];
    }

    private static function target(object $site, string $product): array
    {
        return ['site_id' => (int) $site->id, 'site' => wp_strip_all_tags((string) $site->name), 'product' => $product];
    }

    private static function liveInventory(object $site, string $product): array
    {
        // @wp-test feature=product-mcp-abilities scenario=refuse-ineligible
        if (!ManagedConnection::ready($site) || !SiteInventory::fromSite($site, $product)['connected']) {
            throw new \RuntimeException('The site is disconnected.');
        }
        $inventory = Onboarding::currentInventory($site, $product, false);
        if (!SiteInventory::permitsActions($product, $inventory, [], time()) || $inventory['pro']['state'] === 'legacy') {
            throw new \RuntimeException('The child is ineligible.');
        }
        return $inventory;
    }

    private static function readSetting(object $site, string $product): array
    {
        $inventory = self::liveInventory($site, $product);
        if ($inventory['active_edition'] === '') {
            throw new \RuntimeException('The setting is unavailable.');
        }
        $data = ManagedConnection::settings($site, $product)['result'] ?? null;
        if (!is_array($data) || ($data['product'] ?? null) !== $product
            || ($data['field'] ?? null) !== self::SETTINGS[$product] || !is_bool($data['value'] ?? null)
            || !is_string($data['checksum'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $data['checksum']) !== 1) {
            throw new \RuntimeException('The setting is unavailable.');
        }
        return ['target' => self::target($site, $product), 'field' => $data['field'], 'value' => $data['value'], 'checksum' => $data['checksum']];
    }

    private static function operationSource(object $site, string $product, string $operation, array $inventory): array
    {
        if ($operation === 'activate_license') {
            $result = ManagedConnection::product($site, $product)['result'] ?? null;
            $snapshot = SiteInventory::normalizeStatus($result, $product, $inventory, time());
            $binding = SavedLicenses::revision($product, (int) $site->id);
            if ($binding === '' || ($snapshot['edition'] ?? '') !== 'pro' || $snapshot['entitlement_active']
                || ($snapshot['woocommerce_eligible'] ?? true) === false
                || ($result['capabilities']['license_activation'] ?? null) !== true || ($result['administration_email_valid'] ?? null) !== true) {
                throw new \RuntimeException('A new Pro activation is unavailable.');
            }
            SavedLicenses::resolve($product, (int) $site->id, '');
            return ['binding' => $binding, 'version' => null];
        }
        [$verb, $edition] = explode('_', $operation, 2);
        $other = $edition === 'lite' ? 'pro' : 'lite';
        if ($verb === 'activate') {
            if ($inventory[$edition]['state'] !== 'inactive' || $inventory[$other]['state'] === 'active') {
                throw new \RuntimeException('The edition cannot be activated.');
            }
            return ['binding' => null, 'version' => $inventory[$edition]['version']];
        }
        $catalog = PackageDelivery::catalog();
        if ($catalog === null || $inventory[$edition]['state'] !== 'missing') {
            throw new \RuntimeException('The edition cannot be installed.');
        }
        if ($edition === 'lite') {
            $package = $catalog->litePackage($product);
        } else {
            $source = PackageDelivery::proSource($product);
            $package = $source === null ? null : $catalog->proManifest($product, $source['version']);
        }
        if ($package === null) {
            throw new \RuntimeException('The package is unavailable.');
        }
        return ['binding' => PackageDelivery::packageIdentity($catalog, $package), 'version' => $package['version']];
    }

    private static function review(array $input, array $state, array $preview, callable $execute): array|WP_Error
    {
        // @wp-test feature=product-mcp-abilities scenario=refuse-changed-preview
        $digest = hash('sha256', wp_json_encode($state));
        $dryRun = ($input['dry_run'] ?? false) === true;
        $confirm = ($input['confirm'] ?? false) === true;
        if ($dryRun && !$confirm) {
            $token = bin2hex(random_bytes(32));
            if (!set_transient(self::PLAN_PREFIX . hash('sha256', $token), ['user' => get_current_user_id(), 'digest' => $digest], self::PLAN_TTL)) {
                return new WP_Error('product_preview_failed', 'The Dashboard could not save the preview.');
            }
            return ['outcome' => 'preview', 'preview_token' => $token, 'expires_in' => self::PLAN_TTL] + $preview;
        }
        $token = $input['preview_token'] ?? '';
        if (!$confirm || $dryRun || !is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return new WP_Error('product_preview_required', 'Run a dry run and confirm the returned preview before changing the child.');
        }
        $key = self::PLAN_PREFIX . hash('sha256', $token);
        return Onboarding::locked($key, static function () use ($key, $digest, $execute): array|WP_Error {
            $saved = get_transient($key);
            if (!is_array($saved) || ($saved['user'] ?? null) !== get_current_user_id() || !hash_equals($saved['digest'] ?? '', $digest)) {
                return new WP_Error('product_preview_stale', 'The preview expired or the requested site, operation, or current state has changed.');
            }
            if (!delete_transient($key)) {
                return new WP_Error('product_preview_failed', 'The preview could not be claimed.');
            }
            return $execute();
        });
    }
}
