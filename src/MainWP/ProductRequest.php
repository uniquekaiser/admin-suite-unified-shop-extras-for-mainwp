<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\MainWP;

use InvalidArgumentException;

final class ProductRequest
{
    private const PRODUCTS = [
        'admin-suite' => 'synergetic_admin_suite',
        'unified-shop-extras' => 'synergetic_unified_shop_extras',
    ];

    /** Bind one product operation and its payload before MainWP signs the nonce. */
    public static function bind(array $request, string $product, string $operation, string $key = ''): array
    {
        if (!in_array($operation, ['status_v1', 'activate_license_v1'], true)
            || ($operation === 'status_v1' && $key !== '')
            || ($operation === 'activate_license_v1' && (strlen($key) > 256 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $key) !== 1))) {
            throw new InvalidArgumentException('Invalid MainWP product request.');
        }

        return self::bindPayload($request, $product, $operation, '_license_key', $key);
    }

    public static function settings(array $request, string $product, ?bool $value = null, string $checksum = ''): array
    {
        if ($value === null ? $checksum !== '' : preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1) {
            throw new InvalidArgumentException('A valid settings checksum is required for a write.');
        }
        $operation = $value === null ? 'get_setting_v1' : 'update_setting_v1';
        $payload = $value === null ? '' : ($value ? '1:' : '0:') . $checksum;
        return self::bindPayload($request, $product, $operation, '_setting', $payload);
    }

    private static function bindPayload(array $request, string $product, string $operation, string $field, string $payload): array
    {
        $prefix = self::PRODUCTS[$product] ?? '';
        if ($prefix === '' || ($request['function'] ?? null) !== 'extra_execution') {
            throw new InvalidArgumentException('Invalid MainWP product request.');
        }
        foreach (self::PRODUCTS as $knownPrefix) {
            foreach (['_operation', '_nonce_seed', '_license_key', '_setting'] as $suffix) {
                if (array_key_exists($knownPrefix . $suffix, $request)) {
                    throw new InvalidArgumentException('MainWP product request is already bound.');
                }
            }
        }
        $seed = bin2hex(random_bytes(16));
        $request[$prefix . '_operation'] = $operation;
        $request[$prefix . '_nonce_seed'] = $seed;
        if ($payload !== '') {
            $request[$prefix . $field] = $payload;
        }
        $request['nonce'] = hash('sha256', "synergetic/{$product}/mainwp/v1\n{$seed}\n{$operation}\n{$payload}");
        return $request;
    }
}
