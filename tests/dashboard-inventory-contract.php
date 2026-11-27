<?php
declare(strict_types=1);

use Synergetic\MainWPProducts\MainWP\SiteInventory;

require __DIR__ . '/../src/Release/PublisherCatalog.php';
require __DIR__ . '/../src/MainWP/SiteInventory.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function plugin(string $slug, string $version, int $active = 1): array
{
    return ['slug' => $slug, 'version' => $version, 'active' => $active];
}

$shop = SiteInventory::SHOP;
$woo = plugin('woocommerce/woocommerce.php', '10.5.2');
$pro = plugin('unified-shop-extras/unified-shop-extras.php', '1.11.2');
$lite = plugin('unified-shop-extras-lite/unified-shop-extras-lite.php', '1.11.2', 0);
$admin = plugin('admin-suite-lite/admin-suite-lite.php', '3.1.0');
$site = static fn (array $plugins, array $settings = []): object => (object) ($settings + [
    'plugins' => json_encode($plugins, JSON_THROW_ON_ERROR),
    'dtsSync' => 100,
    'sync_errors' => '',
    'suspended' => 0,
]);

$inventory = SiteInventory::fromSite($site([$woo, $lite, $pro]), $shop);
check($inventory['active_edition'] === 'pro' && $inventory['lite']['state'] === 'inactive', 'The two installed editions must be classified independently.');
check(SiteInventory::permitsActions($shop, $inventory, [], 100), 'Eligible WooCommerce site did not offer actions.');

foreach ([
    [$site([$pro]), 'inactive'],
    [$site([$pro, array_replace($woo, ['active' => 0])]), 'inactive'],
    [$site([$pro, array_replace($woo, ['version' => '3.9.9'])]), 'unsupported'],
    [$site([$pro, array_replace($woo, ['version' => ''])]), 'unknown'],
    [$site([], ['plugins' => '{broken']), 'unknown'],
    [$site([], ['dtsSync' => 0]), 'unknown'],
] as [$child, $expected]) {
    $row = SiteInventory::fromSite($child, $shop);
    check($row['eligibility'] === $expected && !SiteInventory::permitsActions($shop, $row, [], 100), 'Unexpected eligibility or actions for ' . $expected);
}

$legacy = SiteInventory::fromSite($site([$woo, array_replace($pro, ['version' => '1.11.1'])]), $shop);
check($legacy['active_edition'] === '' && $legacy['pro']['state'] === 'legacy', 'A shared basename was misidentified as Pro.');
$twoActive = SiteInventory::fromSite($site([$woo, array_replace($lite, ['active' => 1]), $pro]), $shop);
check($twoActive['active_edition'] === '', 'A conflicting active edition was chosen for a signed license request.');

$adminRow = SiteInventory::fromSite($site([$admin]), 'admin-suite');
check($adminRow['lite']['state'] === 'active' && SiteInventory::permitsActions('admin-suite', $adminRow, [], 100), 'Admin Suite actions depend on WooCommerce.');
$offline = SiteInventory::fromSite($site([$woo, $pro], ['sync_errors' => 'offline']), $shop);
check(!SiteInventory::permitsActions($shop, $offline, [], 100) && $offline['pro']['state'] === 'active', 'Offline rows must retain cached inventory without actions.');

$signed = [
    'contract_version' => 1, 'product' => $shop, 'edition' => 'pro', 'plugin_basename' => $pro['slug'],
    'version' => $pro['version'], 'woocommerce_eligible' => false,
    'license' => ['entitlement_active' => true, 'updates_allowed' => false, 'state' => 'expired', 'key' => 'fixture-key'],
    'capabilities' => ['status' => true], 'email' => 'fixture@example.test',
];
$snapshot = SiteInventory::normalizeStatus($signed, $shop, $inventory, 150);
check($snapshot !== null && $snapshot['entitlement_active'] && !$snapshot['updates_allowed'] && $snapshot['license_state'] === 'expired', 'Entitlement and update rights were conflated.');
check(!str_contains(json_encode($snapshot), 'fixture'), 'Unapproved response fields entered the cache.');
check(!SiteInventory::permitsActions($shop, $inventory, $snapshot, 140), 'Recent signed WooCommerce refusal was ignored.');
check(SiteInventory::permitsActions($shop, $inventory, $snapshot, 160), 'A later MainWP sync did not supersede the earlier signed WooCommerce refusal.');
foreach (['product' => 'admin-suite', 'edition' => 'lite', 'plugin_basename' => $lite['slug'], 'version' => '1.11.1', 'woocommerce_eligible' => null] as $field => $value) {
    $wrong = array_replace($signed, [$field => $value]);
    check(SiteInventory::normalizeStatus($wrong, $shop, $inventory, 150) === null, 'Accepted a reply with mismatched ' . $field);
}

echo "Dashboard inventory contract: PASS\n";
