<?php
declare(strict_types=1);

use Synergetic\MainWPProducts\MainWP\AdminSuiteRequest;
use Synergetic\MainWPProducts\MainWP\ProductRequest;

require __DIR__ . '/../src/MainWP/AdminSuiteRequest.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$request = ['function' => 'extra_execution', 'user' => 'admin'];
$key = 'synthetic-test-key';
$operation = 'activate_license_v1';
$uwe = ProductRequest::bind($request, 'unified-shop-extras', $operation, $key);
$seed = $uwe['synergetic_unified_shop_extras_nonce_seed'];
check($uwe['synergetic_unified_shop_extras_license_key'] === $key, 'Activation must include the key.');
check($uwe['nonce'] === hash('sha256', "synergetic/unified-shop-extras/mainwp/v1\n{$seed}\n{$operation}\n{$key}"), 'UWE binding differs from the Child contract.');
check($uwe['nonce'] !== hash('sha256', "synergetic/admin-suite/mainwp/v1\n{$seed}\n{$operation}\n{$key}"), 'Bindings must be scoped to one product.');
check($uwe['user'] === 'admin' && !isset($uwe['synergetic_admin_suite_operation']), 'Request must preserve the MainWP user and use one namespace.');

$status = ProductRequest::bind($request, 'unified-shop-extras', 'status_v1');
check(!array_key_exists('synergetic_unified_shop_extras_license_key', $status), 'Status must omit the license field.');
check($status['nonce'] !== ProductRequest::bind($request, 'unified-shop-extras', 'status_v1')['nonce'], 'Every request needs its own seed.');

$as = AdminSuiteRequest::bind($request, $operation, $key);
$seed = $as['synergetic_admin_suite_nonce_seed'];
check($as['nonce'] === hash('sha256', "synergetic/admin-suite/mainwp/v1\n{$seed}\n{$operation}\n{$key}"), 'Existing Admin Suite binding changed.');

$checksum = hash('sha256', 'setting document');
foreach (['admin-suite', 'unified-shop-extras'] as $product) {
    $prefix = 'synergetic_' . str_replace('-', '_', $product);
    $read = ProductRequest::settings($request, $product, null, '');
    $write = ProductRequest::settings($request, $product, false, $checksum);
    $seed = $write[$prefix . '_nonce_seed'];
    check($read[$prefix . '_operation'] === 'get_setting_v1' && !isset($read[$prefix . '_setting']), 'Setting read needs no payload.');
    check($write[$prefix . '_setting'] === '0:' . $checksum, 'Setting request must carry the exact boolean and checksum.');
    check($write['nonce'] === hash('sha256', "synergetic/{$product}/mainwp/v1\n{$seed}\nupdate_setting_v1\n0:{$checksum}"),
        'Setting request must bind the action, value and checksum to the signed nonce.');
    check(!array_key_exists($prefix . '_license_key', $read) && !array_key_exists($prefix . '_license_key', $write),
        'Settings requests must omit the license key.');
}

foreach ([
    [$request, 'unknown', 'status_v1', ''],
    [$request, 'unified-shop-extras', 'exec', ''],
    [$request, 'unified-shop-extras', 'status_v1', $key],
    [$request, 'unified-shop-extras', $operation, 'invalid key'],
    [$request, 'unified-shop-extras', $operation, str_repeat('a', 257)],
    [$request + ['synergetic_unified_shop_extras_license_key' => $key], 'unified-shop-extras', 'status_v1', ''],
    [$as, 'unified-shop-extras', 'status_v1', ''],
    [['function' => 'sync'], 'unified-shop-extras', 'status_v1', ''],
] as $arguments) {
    try {
        ProductRequest::bind(...$arguments);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('An invalid request was bound.');
}

echo "MainWP product request contract: PASS\n";
