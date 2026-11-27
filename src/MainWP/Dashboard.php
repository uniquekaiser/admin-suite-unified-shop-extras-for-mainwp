<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\MainWP;

use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_System;
use MainWP\Dashboard\MainWP_System_Utility;
use Throwable;

final class Dashboard
{
    private const REFRESH = 'synergetic_mainwp_products_refresh';
    private const DEFAULT_KEY = 'synergetic_mainwp_products_default_key';
    private const PAGE_SIZE = 40;

    private function __construct(private readonly string $file)
    {
    }

    public static function boot(string $file): void
    {
        if (!class_exists(MainWP_System::class) || version_compare(MainWP_System::$version, '6.5', '<')) {
            add_action('admin_notices', static function (): void {
                if (current_user_can('manage_options')) {
                    echo '<div class="notice notice-warning"><p>' . esc_html__('Admin Suite and Unified Shop Extras requires MainWP Dashboard 6.5 or later.', 'admin-suite-unified-shop-extras-for-mainwp') . '</p></div>';
                }
            });
            return;
        }

        $dashboard = new self($file);
        Abilities::boot($file);
        add_filter('mainwp_getextensions', [$dashboard, 'register']);
        add_action('admin_post_' . self::REFRESH, [$dashboard, 'refresh']);
        add_action('admin_post_' . self::DEFAULT_KEY, [$dashboard, 'defaultKey']);
        add_action('admin_post_' . PackageDelivery::ACTION, [PackageDelivery::class, 'download']);
        add_action('admin_post_nopriv_' . PackageDelivery::ACTION, [PackageDelivery::class, 'download']);
    }

    public function register(array $extensions): array
    {
        $extensions[] = [
            'plugin' => $this->file,
            'name' => __('Admin Suite and Unified Shop Extras', 'admin-suite-unified-shop-extras-for-mainwp'),
            'icon' => plugins_url('assets/products-icon.png', $this->file),
            'callback' => [$this, 'render'],
        ];
        return $extensions;
    }

    public function render(): void
    {
        // @wp-test feature=product-inventory scenario=edition-tabs
        $this->requireAccess();
        $products = $this->productLabels();
        $product = $this->input($_GET, 'product'); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
        $product = isset($products[$product]) ? $product : 'admin-suite';
        $catalog = \mainwp_current_user_can('dashboard', 'install_plugins') ? PackageDelivery::catalog() : null;
        $liteAvailable = $catalog?->litePackage($product) !== null;
        $proAvailable = false;
        if ($catalog !== null && ($source = PackageDelivery::proSource($product)) !== null) {
            try {
                $catalog->proManifest($product, $source['version']);
                $proAvailable = true;
            } catch (\InvalidArgumentException) {
                // The installer requires an approved manifest for the exact Dashboard version.
            }
        }
        $page = $this->pageNumber($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
        $sites = MainWP_DB::instance()->get_websites_for_current_user([
            'select_data' => ['id', 'name', 'url', 'plugins', 'dtsSync', 'sync_errors', 'suspended', 'userid'],
            'extra_view' => [],
            'format' => 'array',
            'offset' => ($page - 1) * self::PAGE_SIZE,
            'rowcount' => self::PAGE_SIZE + 1,
        ]);
        $notice = $this->input($_GET, 'status'); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fixed display messages.
        $noticeText = match ($notice) {
            'saved' => __('Product status updated.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'refused' => __('Status refresh is unavailable for this site. Review its MainWP connection and eligibility.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'failed' => __('MainWP could not confirm product status. The last successful check is shown when available.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'plugin_installed' => __('Installation confirmed. Activate the edition when ready.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'plugin_activated' => __('Plugin activation confirmed on the managed site.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'license_activated' => __('The managed site confirmed its Pro license.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'key_saved' => __('Saved license settings updated.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'license_missing' => __('Enter a one-time key or save a license key first.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'license_unreadable' => __('The assigned key could not be decrypted. Restore the MainWP key files or replace the assigned key.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'license_invalid' => __('The supplied license key format is invalid.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'license_rejected' => __('The license provider rejected this key for the managed site.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'license_unsupported' => __('This child build or connection does not support remote license activation.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'license_email' => __('Set a valid WordPress Administration Email Address on the managed site.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'license_unconfirmed', 'operation_unconfirmed' => __('The child could not confirm the operation. Review its current state before retrying.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'catalog_unavailable' => __('Configure a trusted signed catalog and an HTTPS Dashboard to deliver packages.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'lite_pending' => __('The publisher has not enabled Lite installation for this product.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'pro_source_unavailable' => __('An active, licensed Dashboard Pro copy is required.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'operation_busy' => __('An operation is already in progress for this site. Check its result before retrying.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'operation_refused' => __('The current site, edition, or licensing state does not allow this action.', 'admin-suite-unified-shop-extras-for-mainwp'),
            'operation_failed', 'package_mismatch', 'package_storage', 'lite_download_failed', 'license_storage' => __('The operation could not complete. Check the signed catalog, Dashboard package source, and MainWP connection.', 'admin-suite-unified-shop-extras-for-mainwp'),
            default => '',
        };
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Admin Suite and Unified Shop Extras', 'admin-suite-unified-shop-extras-for-mainwp'); ?></h1>
            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Products', 'admin-suite-unified-shop-extras-for-mainwp'); ?>">
                <?php foreach ($products as $slug => $label) : ?>
                    <a href="<?php echo esc_url($this->url($slug)); ?>" class="nav-tab<?php echo $slug === $product ? ' nav-tab-active' : ''; ?>"<?php echo $slug === $product ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($noticeText !== '') : ?>
                <div class="notice <?php echo in_array($notice, ['saved', 'key_saved', 'plugin_installed', 'plugin_activated', 'license_activated'], true) ? 'notice-success' : 'notice-warning'; ?>"><p><?php echo esc_html($noticeText); ?></p></div>
            <?php endif; ?>
            <p><?php esc_html_e('Edition status comes from MainWP sync. Refresh a site to check its current product license status.', 'admin-suite-unified-shop-extras-for-mainwp'); ?></p>
            <details style="margin-bottom:1em">
                <summary><?php echo esc_html(SavedLicenses::has($product) ? __('Default license key saved', 'admin-suite-unified-shop-extras-for-mainwp') : __('Set default license key', 'admin-suite-unified-shop-extras-for-mainwp')); ?></summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::DEFAULT_KEY); ?>">
                    <input type="hidden" name="product" value="<?php echo esc_attr($product); ?>">
                    <?php wp_nonce_field(self::DEFAULT_KEY . '_' . $product, '_wpnonce', false); ?>
                    <label><?php esc_html_e('License key', 'admin-suite-unified-shop-extras-for-mainwp'); ?> <input type="password" name="license_key" maxlength="256" autocomplete="new-password"></label>
                    <button class="button" type="submit" name="operation" value="save"><?php esc_html_e('Save default', 'admin-suite-unified-shop-extras-for-mainwp'); ?></button>
                    <?php if (SavedLicenses::has($product)) : ?>
                        <button class="button" type="submit" name="operation" value="clear"><?php esc_html_e('Clear default', 'admin-suite-unified-shop-extras-for-mainwp'); ?></button>
                    <?php endif; ?>
                </form>
            </details>
            <div style="overflow-x:auto">
                <table class="widefat striped">
                    <thead><tr>
                        <th scope="col"><?php esc_html_e('Site', 'admin-suite-unified-shop-extras-for-mainwp'); ?></th>
                        <th scope="col"><?php esc_html_e('Lite', 'admin-suite-unified-shop-extras-for-mainwp'); ?></th>
                        <th scope="col"><?php esc_html_e('Pro', 'admin-suite-unified-shop-extras-for-mainwp'); ?></th>
                        <th scope="col"><?php esc_html_e('Pro license', 'admin-suite-unified-shop-extras-for-mainwp'); ?></th>
                        <?php if ($product === SiteInventory::SHOP) : ?>
                            <th scope="col"><?php esc_html_e('WooCommerce', 'admin-suite-unified-shop-extras-for-mainwp'); ?></th>
                        <?php endif; ?>
                        <th scope="col"><?php esc_html_e('Actions', 'admin-suite-unified-shop-extras-for-mainwp'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach (array_slice($sites, 0, self::PAGE_SIZE) as $site) : ?>
                        <?php $this->renderSite($site, $product, $page, $liteAvailable, $proAvailable); ?>
                    <?php endforeach; ?>
                    <?php if ($sites === []) : ?>
                        <tr><td colspan="<?php echo $product === SiteInventory::SHOP ? '6' : '5'; ?>"><?php esc_html_e('No managed sites on this page.', 'admin-suite-unified-shop-extras-for-mainwp'); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($page > 1 || count($sites) > self::PAGE_SIZE) : ?>
                <p>
                    <?php if ($page > 1) : ?>
                        <a class="button" href="<?php echo esc_url($this->url($product, $page - 1)); ?>"><?php esc_html_e('Previous page', 'admin-suite-unified-shop-extras-for-mainwp'); ?></a>
                    <?php endif; ?>
                    <?php if (count($sites) > self::PAGE_SIZE) : ?>
                        <a class="button" href="<?php echo esc_url($this->url($product, $page + 1)); ?>"><?php esc_html_e('Next page', 'admin-suite-unified-shop-extras-for-mainwp'); ?></a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderSite(object $site, string $product, int $page, bool $liteAvailable, bool $proAvailable): void
    {
        $inventory = SiteInventory::fromSite($site, $product);
        $cached = $this->cached($site, $product, $inventory);
        $snapshot = $cached['snapshot'] ?? [];
        $canAct = SiteInventory::permitsActions($product, $inventory, $snapshot, (int) ($site->dtsSync ?? 0))
            && MainWP_System_Utility::can_edit_website($site);
        // @wp-test feature=product-inventory scenario=woocommerce-actions
        $wooLabel = match (SiteInventory::wooEligibility($inventory, $snapshot, (int) ($site->dtsSync ?? 0))) {
            'eligible' => __('Eligible', 'admin-suite-unified-shop-extras-for-mainwp'),
            'inactive' => __('WooCommerce is inactive or unavailable', 'admin-suite-unified-shop-extras-for-mainwp'),
            'unsupported' => __('WooCommerce version is unsupported', 'admin-suite-unified-shop-extras-for-mainwp'),
            default => __('WooCommerce status is unknown', 'admin-suite-unified-shop-extras-for-mainwp'),
        };
        $siteId = (int) $site->id;
        ?>
        <tr>
            <th scope="row"><strong><?php echo esc_html($site->name ?: $site->url); ?></strong>
                <br><small><?php echo esc_html($site->url); ?></small>
                <?php if (!$inventory['connected']) : ?>
                    <br><small><?php esc_html_e('MainWP sync required', 'admin-suite-unified-shop-extras-for-mainwp'); ?></small>
                <?php endif; ?>
            </th>
            <td><?php echo esc_html($this->editionLabel($inventory['lite'])); ?></td>
            <td><?php echo esc_html($this->editionLabel($inventory['pro'])); ?></td>
            <td><?php echo esc_html($this->licenseLabel($inventory, $cached)); ?>
                <?php if ($this->hasCurrentProSnapshot($inventory, $snapshot)) : ?>
                    <br><small><?php
                        /* translators: %s: Local date and time of the last signed check. */
                        echo esc_html(sprintf(__('Last confirmed %s', 'admin-suite-unified-shop-extras-for-mainwp'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $snapshot['checked_at'])));
                    ?></small>
                <?php endif; ?>
            </td>
            <?php if ($product === SiteInventory::SHOP) : ?>
                <td><?php echo esc_html($wooLabel); ?></td>
            <?php endif; ?>
            <td>
                <?php if ($canAct) : ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=managesites&dashboard=' . $siteId)); ?>"><?php esc_html_e('Manage site', 'admin-suite-unified-shop-extras-for-mainwp'); ?></a>
                    <?php if ($this->canRefresh($site, $inventory)) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::REFRESH); ?>">
                            <input type="hidden" name="product" value="<?php echo esc_attr($product); ?>">
                            <input type="hidden" name="site_id" value="<?php echo esc_attr((string) $siteId); ?>">
                            <input type="hidden" name="pg" value="<?php echo esc_attr((string) $page); ?>">
                            <?php wp_nonce_field($this->nonceAction($product, $siteId), '_wpnonce', false); ?>
                            <button class="button" type="submit"><?php esc_html_e('Refresh status', 'admin-suite-unified-shop-extras-for-mainwp'); ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if (ManagedConnection::ready($site) && $inventory['pro']['state'] !== 'legacy') : ?>
                        <?php $this->renderOnboarding($siteId, $product, $page, $inventory, $snapshot, $liteAvailable, $proAvailable); ?>
                    <?php endif; ?>
                <?php else : ?>
                    <span aria-label="<?php esc_attr_e('No actions available', 'admin-suite-unified-shop-extras-for-mainwp'); ?>">&mdash;</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function renderOnboarding(int $id, string $product, int $page, array $inventory, array $snapshot, bool $liteAvailable, bool $proAvailable): void
    {
        // @wp-test feature=single-site-onboarding scenario=install-activate
        $canActivate = \mainwp_current_user_can('dashboard', 'activate_deactivate_plugins');
        $override = SavedLicenses::has($product, $id);
        ?>
        <details>
            <summary><?php esc_html_e('Product setup', 'admin-suite-unified-shop-extras-for-mainwp'); ?></summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::REFRESH); ?>">
                <input type="hidden" name="product" value="<?php echo esc_attr($product); ?>">
                <input type="hidden" name="site_id" value="<?php echo esc_attr((string) $id); ?>">
                <input type="hidden" name="pg" value="<?php echo esc_attr((string) $page); ?>">
                <?php wp_nonce_field($this->nonceAction($product, $id), '_wpnonce', false); ?>
                <?php foreach (['lite', 'pro'] as $edition) : ?>
                    <?php $other = $edition === 'lite' ? 'pro' : 'lite'; ?>
                    <?php if ($inventory[$edition]['state'] === 'missing' && ($edition === 'lite' ? $liteAvailable : $proAvailable)) : ?>
                        <button class="button" type="submit" name="operation" value="<?php echo esc_attr('install_' . $edition); ?>"><?php echo esc_html($edition === 'lite' ? __('Install Lite', 'admin-suite-unified-shop-extras-for-mainwp') : __('Install Pro', 'admin-suite-unified-shop-extras-for-mainwp')); ?></button>
                    <?php elseif ($canActivate && $inventory[$edition]['state'] === 'inactive' && $inventory[$other]['state'] !== 'active') : ?>
                        <button class="button" type="submit" name="operation" value="<?php echo esc_attr('activate_' . $edition); ?>"><?php echo esc_html($edition === 'lite' ? __('Activate Lite', 'admin-suite-unified-shop-extras-for-mainwp') : __('Activate Pro', 'admin-suite-unified-shop-extras-for-mainwp')); ?></button>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!PackageDelivery::configured() && in_array('missing', [$inventory['lite']['state'], $inventory['pro']['state']], true)) : ?>
                    <p><?php esc_html_e('Installation requires a configured signed catalog.', 'admin-suite-unified-shop-extras-for-mainwp'); ?></p>
                <?php else : ?>
                    <?php if (!$liteAvailable && $inventory['lite']['state'] === 'missing') : ?>
                        <p><?php esc_html_e('Lite installation requires an approved download.', 'admin-suite-unified-shop-extras-for-mainwp'); ?></p>
                    <?php endif; ?>
                    <?php if (!$proAvailable && $inventory['pro']['state'] === 'missing') : ?>
                        <p><?php esc_html_e('Pro installation requires an active, licensed Dashboard copy and a matching approved manifest.', 'admin-suite-unified-shop-extras-for-mainwp'); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
                <p><label><?php esc_html_e('License key', 'admin-suite-unified-shop-extras-for-mainwp'); ?> <input type="password" name="license_key" maxlength="256" autocomplete="new-password"></label></p>
                <?php if ($canActivate && $inventory['active_edition'] === 'pro' && !($this->hasCurrentProSnapshot($inventory, $snapshot) && $snapshot['entitlement_active'])) : ?>
                    <button class="button button-primary" type="submit" name="operation" value="activate_license"><?php esc_html_e('Activate license', 'admin-suite-unified-shop-extras-for-mainwp'); ?></button>
                <?php endif; ?>
                <button class="button" type="submit" name="operation" value="save_override"><?php esc_html_e('Save site key', 'admin-suite-unified-shop-extras-for-mainwp'); ?></button>
                <?php if ($override) : ?>
                    <button class="button" type="submit" name="operation" value="clear_override"><?php esc_html_e('Clear site key', 'admin-suite-unified-shop-extras-for-mainwp'); ?></button>
                <?php endif; ?>
                <p class="description"><?php echo esc_html($override ? __('Site key saved. An entered key takes precedence for one activation.', 'admin-suite-unified-shop-extras-for-mainwp') : __('Leave the key blank during activation to use the product default.', 'admin-suite-unified-shop-extras-for-mainwp')); ?></p>
            </form>
        </details>
        <?php
    }

    public function refresh(): void
    {
        // @wp-test feature=product-inventory scenario=refresh-license
        // @wp-test feature=product-inventory scenario=restrict-site
        $this->requireAccess();
        if ($this->input($_SERVER, 'REQUEST_METHOD') !== 'POST') {
            wp_die(esc_html__('Use the product page to refresh status.', 'admin-suite-unified-shop-extras-for-mainwp'), '', ['response' => 405]);
        }
        $product = $this->input($_POST, 'product'); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Select the site- and product-specific nonce action.
        $siteId = $this->positiveId($this->input($_POST, 'site_id')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Select the site- and product-specific nonce action.
        if (!isset($this->productLabels()[$product]) || $siteId === 0) {
            wp_die(esc_html__('Invalid product or site.', 'admin-suite-unified-shop-extras-for-mainwp'), '', ['response' => 400]);
        }
        check_admin_referer($this->nonceAction($product, $siteId));
        $page = $this->pageNumber($_POST);
        $db = MainWP_DB::instance();
        $site = $db->get_website_by_id($siteId);
        if (!is_object($site) || !MainWP_System_Utility::can_edit_website($site)) {
            wp_die(esc_html__('You cannot refresh this managed site.', 'admin-suite-unified-shop-extras-for-mainwp'), '', ['response' => 403]);
        }
        $inventory = SiteInventory::fromSite($site, $product);
        $cached = $this->cached($site, $product, $inventory);
        $operation = $this->input($_POST, 'operation');
        if ($operation !== '') {
            // @wp-test feature=single-site-onboarding scenario=refuse-ineligible
            $permission = match (true) {
                str_starts_with($operation, 'install_') => \mainwp_current_user_can('dashboard', 'install_plugins'),
                str_starts_with($operation, 'activate_') => \mainwp_current_user_can('dashboard', 'activate_deactivate_plugins'),
                default => true,
            };
            if (!$permission || !in_array($operation, Onboarding::OPERATIONS, true) || !ManagedConnection::ready($site)
                || !SiteInventory::permitsActions($product, $inventory, $cached['snapshot'] ?? [], (int) ($site->dtsSync ?? 0))) {
                $this->redirect($product, $page, 'operation_refused');
            }
            try {
                $status = Onboarding::run($site, $product, $operation, $this->input($_POST, 'license_key'));
            } catch (Throwable) {
                $status = 'operation_busy';
            }
            $this->redirect($product, $page, $status);
        }
        if (!SiteInventory::permitsActions($product, $inventory, $cached['snapshot'] ?? [], (int) ($site->dtsSync ?? 0))
            || !$this->canRefresh($site, $inventory)) {
            $this->redirect($product, $page, 'refused');
        }

        $envelope = $this->requestStatus($site, $product);
        $recordedAt = microtime(true);
        $snapshot = SiteInventory::normalizeStatus($envelope['result'] ?? null, $product, $inventory, (int) $recordedAt);
        $key = Onboarding::statusKey($product);
        if ($snapshot !== null) {
            $value = $snapshot + ['recorded_at' => $recordedAt];
        } else {
            // Failed requests write their own option, so they cannot overwrite a successful check.
            $key .= '_failure';
            $edition = $inventory['active_edition'];
            $value = [
                'code' => $envelope['outcome'] ?? 'failed',
                'edition' => $edition,
                'version' => $inventory[$edition]['version'],
                'recorded_at' => $recordedAt,
            ];
        }
        $saved = $db->update_website_option($siteId, $key, wp_json_encode($value)) !== false;
        $this->redirect($product, $page, $saved && $snapshot !== null ? 'saved' : 'failed');
    }

    public function defaultKey(): void
    {
        // @wp-test feature=single-site-onboarding scenario=encrypted-keys
        $this->requireAccess();
        if ($this->input($_SERVER, 'REQUEST_METHOD') !== 'POST') {
            wp_die('', '', ['response' => 405]);
        }
        $product = $this->input($_POST, 'product'); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Select the product-specific nonce action.
        if (!isset($this->productLabels()[$product])) {
            wp_die('', '', ['response' => 400]);
        }
        check_admin_referer(self::DEFAULT_KEY . '_' . $product);
        $operation = $this->input($_POST, 'operation');
        $key = $this->input($_POST, 'license_key');
        if (!in_array($operation, ['save', 'clear'], true) || ($operation === 'save' && $key === '')) {
            $this->redirect($product, 1, 'license_missing');
        }
        try {
            Onboarding::locked('default-' . $product, static fn () => SavedLicenses::save($product, 0, $operation === 'clear' ? '' : $key));
            $status = 'key_saved';
        } catch (Throwable) {
            $status = 'license_storage';
        }
        $this->redirect($product, 1, $status);
    }

    private function requestStatus(object $site, string $product): array
    {
        $target = clone $site;
        $target->verify_certificate = 1;
        $bound = false;
        $binder = static function (array $request, object $website, string $action, mixed $params, bool $advanced) use ($product, $target, &$bound): array {
            if ($website !== $target || $action !== 'extra_execution' || !$advanced) {
                return $request;
            }
            $bound = true;
            return ProductRequest::bind($request, $product, 'status_v1');
        };
        add_filter('mainwp_get_post_data_authed', $binder, PHP_INT_MAX, 5);
        try {
            $reply = MainWP_Connect::fetch_url_authed($target, 'extra_execution', [], false, false, false, null, true, ['root_only' => true]);
        } catch (Throwable) {
            return [];
        } finally {
            remove_filter('mainwp_get_post_data_authed', $binder, PHP_INT_MAX);
        }
        if (!$bound || !is_array($reply) || isset($reply['error'])) {
            return [];
        }
        $namespace = 'synergetic_' . str_replace('-', '_', $product);
        $envelope = $reply[$namespace] ?? $reply['information'][$namespace] ?? null;
        if ($envelope === null) {
            return ['outcome' => 'unsupported'];
        }
        return is_array($envelope) && ($envelope['ok'] ?? null) === true ? $envelope : [];
    }

    private function canRefresh(object $site, array $inventory): bool
    {
        return $inventory['active_edition'] !== ''
            && wp_parse_url($site->url, PHP_URL_SCHEME) === 'https'
            && (int) MainWP_DB::instance()->get_website_option($site, 'support_advanced_sign', 0) === 1;
    }

    private function cached(object $site, string $product, array $inventory): array
    {
        $edition = $inventory['active_edition'];
        if ($edition === '') {
            return [];
        }
        $key = Onboarding::statusKey($product);
        $options = MainWP_DB::instance()->get_website_options_array($site, [$key, $key . '_failure']);
        $snapshot = (array) json_decode($options[$key] ?? '', true);
        $failure = (array) json_decode($options[$key . '_failure'] ?? '', true);
        $latestFailed = ($failure['edition'] ?? '') === $edition
            && ($failure['version'] ?? '') === $inventory[$edition]['version']
            && ($failure['recorded_at'] ?? 0) > ($snapshot['recorded_at'] ?? 0);
        return [
            'snapshot' => $snapshot,
            'outcome' => $latestFailed ? $failure['code'] : 'verified',
        ];
    }

    private function hasCurrentProSnapshot(array $inventory, array $snapshot): bool
    {
        return $inventory['active_edition'] === 'pro' && ($snapshot['edition'] ?? '') === 'pro'
            && $inventory['pro']['version'] === ($snapshot['version'] ?? '') && is_int($snapshot['checked_at'] ?? null);
    }

    private function editionLabel(array $edition): string
    {
        $label = match ($edition['state']) {
            'active' => __('Active', 'admin-suite-unified-shop-extras-for-mainwp'),
            'inactive' => __('Installed, inactive', 'admin-suite-unified-shop-extras-for-mainwp'),
            'missing' => __('Not installed', 'admin-suite-unified-shop-extras-for-mainwp'),
            'legacy' => __('Legacy shared path, edition unconfirmed', 'admin-suite-unified-shop-extras-for-mainwp'),
            default => __('Awaiting sync', 'admin-suite-unified-shop-extras-for-mainwp'),
        };
        return $edition['version'] === '' ? $label : $label . ' · ' . $edition['version'];
    }

    private function licenseLabel(array $inventory, array $cached): string
    {
        $state = $inventory['pro']['state'];
        $snapshot = $cached['snapshot'] ?? [];
        if ($state !== 'active') {
            return match ($state) {
                'inactive' => __('Pro is inactive', 'admin-suite-unified-shop-extras-for-mainwp'),
                'legacy' => __('Upgrade to a separate Lite or Pro build', 'admin-suite-unified-shop-extras-for-mainwp'),
                'missing' => __('Not applicable', 'admin-suite-unified-shop-extras-for-mainwp'),
                default => __('Awaiting sync', 'admin-suite-unified-shop-extras-for-mainwp'),
            };
        }
        if (!$this->hasCurrentProSnapshot($inventory, $snapshot)) {
            return match ($cached['outcome'] ?? '') {
                'unsupported' => __('Upgrade the child product to report license status', 'admin-suite-unified-shop-extras-for-mainwp'),
                'failed' => __('Could not verify license status', 'admin-suite-unified-shop-extras-for-mainwp'),
                default => __('Not yet checked', 'admin-suite-unified-shop-extras-for-mainwp'),
            };
        }
        $label = match (true) {
            ($snapshot['entitlement_active'] ?? false) && ($snapshot['updates_allowed'] ?? false) => __('Active • Updates allowed', 'admin-suite-unified-shop-extras-for-mainwp'),
            ($snapshot['entitlement_active'] ?? false) && $snapshot['license_state'] === 'expired' => __('Entitled • License expired', 'admin-suite-unified-shop-extras-for-mainwp'),
            (bool) ($snapshot['entitlement_active'] ?? false) => __('Entitled • Updates unavailable', 'admin-suite-unified-shop-extras-for-mainwp'),
            $snapshot['license_state'] === 'revocation_pending' => __('Revocation pending', 'admin-suite-unified-shop-extras-for-mainwp'),
            $snapshot['license_state'] === 'unreachable' => __('License provider unavailable', 'admin-suite-unified-shop-extras-for-mainwp'),
            $snapshot['license_state'] === 'expired' => __('License expired', 'admin-suite-unified-shop-extras-for-mainwp'),
            $snapshot['license_state'] === 'invalid' => __('License rejected', 'admin-suite-unified-shop-extras-for-mainwp'),
            default => __('License inactive', 'admin-suite-unified-shop-extras-for-mainwp'),
        };
        if (($cached['outcome'] ?? '') !== 'verified' || !$inventory['connected']) {
            /* translators: %s: Previously verified license status. */
            return sprintf(__('Last known: %s', 'admin-suite-unified-shop-extras-for-mainwp'), $label);
        }
        return $label;
    }

    private function requireAccess(): void
    {
        if (!current_user_can('manage_options') || !\mainwp_current_user_can('extension', dirname(plugin_basename($this->file)))) {
            wp_die(esc_html__('You cannot access this MainWP extension.', 'admin-suite-unified-shop-extras-for-mainwp'), '', ['response' => 403]);
        }
    }

    private function productLabels(): array
    {
        return [
            'admin-suite' => __('Admin Suite', 'admin-suite-unified-shop-extras-for-mainwp'),
            SiteInventory::SHOP => __('Unified Shop Extras', 'admin-suite-unified-shop-extras-for-mainwp'),
        ];
    }

    private function url(string $product, int $page = 1, string $status = ''): string
    {
        $extension = str_replace(' ', '-', ucwords(str_replace('-', ' ', dirname(plugin_basename($this->file)))));
        return add_query_arg(['page' => 'Extensions-' . $extension, 'product' => $product, 'pg' => $page, 'status' => $status], admin_url('admin.php'));
    }

    private function nonceAction(string $product, int $siteId): string
    {
        return self::REFRESH . '_' . $product . '_' . $siteId;
    }

    private function pageNumber(array $request): int
    {
        return max(1, min(1000000, $this->positiveId($this->input($request, 'pg'))));
    }

    private function positiveId(string $value): int
    {
        return ctype_digit($value) && (string) (int) $value === $value ? (int) $value : 0;
    }

    private function input(array $request, string $key): string
    {
        return is_string($request[$key] ?? null) ? wp_unslash($request[$key]) : '';
    }

    private function redirect(string $product, int $page, string $status): never
    {
        wp_safe_redirect($this->url($product, $page, $status), 303);
        exit;
    }
}
