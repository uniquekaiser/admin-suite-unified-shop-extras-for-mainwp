<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\MainWP;

use Synergetic\MainWPProducts\Release\PublisherCatalog;

final class SiteInventory
{
    public const SHOP = 'unified-shop-extras';
    public const WOO_MINIMUM = '4.0';

    public static function fromSite(object $site, string $product): array
    {
        $plugins = is_string($site->plugins ?? null) ? json_decode($site->plugins, true) : null;
        $byBasename = [];
        if (is_array($plugins) && array_is_list($plugins)) {
            foreach ($plugins as $plugin) {
                if (is_array($plugin) && is_string($plugin['slug'] ?? null)) {
                    $byBasename[$plugin['slug']] = $plugin;
                }
            }
        } else {
            $plugins = null;
        }
        $known = $plugins !== null && (int) ($site->dtsSync ?? 0) > 0;
        $lite = self::edition($byBasename[PublisherCatalog::PRODUCTS[$product]['lite']] ?? null, $known);
        $pro = self::edition($byBasename[PublisherCatalog::PRODUCTS[$product]['pro']] ?? null, $known);
        $proWasActive = $pro['state'] === 'active';
        if ($product === self::SHOP && in_array($pro['state'], ['active', 'inactive'], true)
            && ($pro['version'] === '' || version_compare($pro['version'], '1.11.2', '<'))) {
            $pro['state'] = 'legacy';
        }

        $woo = self::edition($byBasename['woocommerce/woocommerce.php'] ?? null, $known);
        $eligibility = match ($woo['state']) {
            'active' => $woo['version'] === '' ? 'unknown'
                : (version_compare($woo['version'], self::WOO_MINIMUM, '>=') ? 'eligible' : 'unsupported'),
            'unknown' => 'unknown',
            default => 'inactive',
        };
        $activeEdition = match (true) {
            $pro['state'] === 'active' && $lite['state'] !== 'active' => 'pro',
            $lite['state'] === 'active' && !$proWasActive => 'lite',
            default => '',
        };

        return [
            'lite' => $lite,
            'pro' => $pro,
            'eligibility' => $eligibility,
            'active_edition' => $activeEdition,
            'connected' => (int) ($site->dtsSync ?? 0) > 0 && empty($site->sync_errors) && empty($site->suspended),
        ];
    }

    public static function permitsActions(string $product, array $inventory, array $snapshot, int $syncedAt): bool
    {
        return $inventory['connected']
            && ($product !== self::SHOP || self::wooEligibility($inventory, $snapshot, $syncedAt) === 'eligible');
    }

    public static function wooEligibility(array $inventory, array $snapshot, int $syncedAt): string
    {
        $signedRefusal = ($snapshot['checked_at'] ?? 0) >= $syncedAt && ($snapshot['woocommerce_eligible'] ?? null) === false;
        return $inventory['eligibility'] === 'eligible' && $signedRefusal ? 'inactive' : $inventory['eligibility'];
    }

    /** Whitelist the signed reply and require it to describe the edition MainWP synced. */
    public static function normalizeStatus(mixed $result, string $product, array $inventory, int $checkedAt): ?array
    {
        $edition = $inventory['active_edition'];
        if ($edition === '' || !is_array($result)
            || ($result['contract_version'] ?? null) !== 1 || ($result['product'] ?? null) !== $product
            || ($result['edition'] ?? null) !== $edition
            || ($result['plugin_basename'] ?? null) !== PublisherCatalog::PRODUCTS[$product][$edition]
            || ($result['version'] ?? '') === '' || ($result['version'] ?? '') !== $inventory[$edition]['version']
            || ($result['capabilities']['status'] ?? null) !== true
            || !is_bool($result['license']['entitlement_active'] ?? null)
            || !is_bool($result['license']['updates_allowed'] ?? null)
            || ($product === self::SHOP && !is_bool($result['woocommerce_eligible'] ?? null))) {
            return null;
        }

        $state = $result['license']['state'] ?? '';
        return [
            'edition' => $edition,
            'version' => $result['version'],
            'checked_at' => $checkedAt,
            'entitlement_active' => $result['license']['entitlement_active'],
            'updates_allowed' => $result['license']['updates_allowed'],
            'license_state' => in_array($state, ['valid', 'invalid', 'expired', 'unreachable', 'deactivated', 'revoked', 'revocation_pending', 'missing_key', 'missing_admin_email', 'not_applicable'], true) ? $state : 'unknown',
            'woocommerce_eligible' => $product === self::SHOP ? $result['woocommerce_eligible'] : null,
        ];
    }

    private static function edition(?array $plugin, bool $synced): array
    {
        $version = $plugin['version'] ?? '';
        $version = is_string($version) && preg_match('/\A\d[a-zA-Z0-9._+-]{0,63}\z/', $version) ? $version : '';
        return [
            'state' => $plugin === null ? ($synced ? 'missing' : 'unknown')
                : (in_array($plugin['active'] ?? null, [1, '1', true], true) ? 'active' : 'inactive'),
            'version' => $version,
        ];
    }
}
