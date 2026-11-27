<?php

use MainWP\MCP\Bridge\Settings;
use MainWP\MCP\Bridge\Tool_Factory;
use Synergetic\MainWPProducts\MainWP\Abilities;
use Synergetic\MainWPProducts\MainWP\SavedLicenses;

// Run with wp eval-file on the disposable MainWP fixture only.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('The local MainWP fixture is required.');
}

function expect_result(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function invoke_product(string $name, array $input): array|WP_Error
{
    $ability = wp_get_ability(Abilities::SLUG . '/' . $name);
    expect_result($ability instanceof WP_Ability, 'Missing ability ' . $name);
    return $ability->execute($input);
}

function result_for(string $name, array $input): array
{
    $result = invoke_product($name, $input);
    expect_result(is_array($result), $name . ' returned ' . (is_wp_error($result) ? $result->get_error_code() : gettype($result)));
    return $result;
}

function expect_refusal(string $name, array $input, ?string $code = null): void
{
    $result = invoke_product($name, $input);
    expect_result(is_wp_error($result) && ($code === null || $result->get_error_code() === $code),
        $name . ' did not refuse as expected' . (is_wp_error($result) ? ' (' . $result->get_error_code() . ')' : ''));
}

function preview_product(string $name, array $input): array
{
    $preview = result_for($name, $input + ['dry_run' => true]);
    expect_result(($preview['outcome'] ?? '') === 'preview' && strlen($preview['preview_token'] ?? '') === 64, 'Invalid preview');
    return $preview;
}

wp_set_current_user(1);
$phase = $args[0] ?? '';
$product = $args[1] ?? 'admin-suite';
$target = ['site_id' => 1, 'product' => $product];

if ($phase === 'setup') {
    $settings = Settings::get();
    expect_result(in_array(Abilities::SLUG, $settings['ability_namespaces'], true), 'MainWP MCP Bridge namespace missing');
    $found = Tool_Factory::discover($settings);
    expect_result(count(array_filter($found, static fn (WP_Ability $ability): bool =>
        str_starts_with($ability->get_name(), Abilities::SLUG . '/'))) === 5,
        'MCP Bridge did not discover every product ability');
} elseif ($phase === 'read') {
    $list = result_for('list-sites', ['product' => $product]);
    expect_result(in_array(1, array_column(array_column($list['sites'], 'target'), 'site_id'), true), 'Expected managed site absent');
    $status = result_for('get-status', $target);
    expect_result($status['target']['site_id'] === 1 && $status['inventory']['pro']['state'] === 'missing', 'Unexpected starting edition');
    wp_set_current_user(3);
    expect_refusal('list-sites', ['product' => $product], 'ability_invalid_permissions');
    wp_set_current_user(1);
    expect_refusal('get-status', ['site_id' => 999, 'product' => $product], 'product_site_unavailable');
    wp_set_current_user(2);
    $list = result_for('list-sites', ['product' => $product]);
    expect_result(array_column(array_column($list['sites'], 'target'), 'site_id') === [1], 'Scoped administrator saw other sites');
    expect_refusal('get-status', ['site_id' => 2, 'product' => $product], 'product_site_unavailable');
} elseif ($phase === 'setup-lite' || $phase === 'setup-product') {
    $key = $product === 'admin-suite' ? 'synthetic-activation-key' : 'fixture-uwe-key';

    $operations = ['install_lite' => 'plugin_installed', 'activate_lite' => 'plugin_activated'];
    if ($phase === 'setup-product') {
        SavedLicenses::save($product, 1, $key);
        $operations = ['install_pro' => 'plugin_installed', 'activate_pro' => 'plugin_activated', 'activate_license' => 'license_activated'];
    }
    foreach ($operations as $operation => $outcome) {
        $input = $target + ['operation' => $operation];
        expect_refusal('onboard-site', $input + ['confirm' => true], 'product_preview_required');
        $plan = preview_product('onboard-site', $input);
        expect_result(!str_contains(wp_json_encode($plan), $key), 'Saved license leaked in preview');
        $commit = $input + ['confirm' => true, 'preview_token' => $plan['preview_token']];
        $applied = result_for('onboard-site', $commit);
        expect_result(($applied['outcome'] ?? '') === $outcome && !str_contains(wp_json_encode($applied), $key), 'Wrong onboarding result');
        expect_refusal('onboard-site', $commit);
    }

    $status = result_for('get-status', $target);
    if ($phase === 'setup-lite') {
        expect_result($status['inventory']['active_edition'] === 'lite', 'Lite activation did not persist');
    } else {
        expect_result($status['license_source'] === 'live' && $status['license']['entitlement_active'] === true
            && !str_contains(wp_json_encode($status), $key), 'Signed status did not verify activation');
        expect_refusal('onboard-site', $target + ['operation' => 'activate_license', 'license_key' => $key]);
    }
} elseif ($phase === 'setting') {
    $before = result_for('get-setting', $target);
    $input = $target + ['value' => !$before['value']];
    $plan = preview_product('update-setting', $input);
    expect_result(result_for('get-setting', $target) === $before, 'The preview changed the setting');
    $confirmation = ['confirm' => true, 'preview_token' => $plan['preview_token']];
    expect_refusal('update-setting', $target + ['value' => $before['value']] + $confirmation, 'product_preview_stale');
    $changed = result_for('update-setting', $input + $confirmation);
    expect_result($changed['outcome'] === 'updated' && $changed['value'] === $input['value'], 'Signed setting change was not verified');
    expect_refusal('update-setting', $input + $confirmation, 'product_preview_stale');
    expect_result(result_for('get-setting', $target)['value'] === $input['value'], 'The setting failed to persist');
} elseif ($phase === 'no-woocommerce') {
    $shop = ['site_id' => 1, 'product' => 'unified-shop-extras'];
    expect_refusal('get-setting', $shop, 'product_setting_unavailable');
    expect_refusal('update-setting', $shop + ['value' => true, 'dry_run' => true], 'product_setting_unavailable');
    expect_refusal('onboard-site', $shop + ['operation' => 'activate_lite', 'dry_run' => true], 'product_operation_unavailable');
} else {
    throw new RuntimeException('Unknown test phase.');
}

echo "MainWP product ability runtime ${phase} ${product}: PASS\n";
