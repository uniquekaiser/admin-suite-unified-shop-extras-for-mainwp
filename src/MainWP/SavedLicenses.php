<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\MainWP;

use MainWP\Dashboard\MainWP_Credential_Storage as Crypto;
use RuntimeException;

final class SavedLicenses
{
    public static function has(string $product, int $siteId = 0): bool
    {
        return get_option(self::option($product, $siteId), null) !== null;
    }

    /** A server-side identity for the assigned encrypted value, including rotation. */
    public static function revision(string $product, int $siteId): string
    {
        $option = self::option($product, self::has($product, $siteId) ? $siteId : 0);
        $stored = get_option($option, null);
        if (!Crypto::is_credential_envelope($stored)) {
            return '';
        }
        return hash('sha256', $option . "\0" . $stored);
    }

    public static function resolve(string $product, int $siteId, string $oneTime, ?string $expectedRevision = null): string
    {
        if ($oneTime !== '') {
            return self::validate($oneTime);
        }
        $option = self::option($product, self::has($product, $siteId) ? $siteId : 0);
        $stored = get_option($option, null);
        if ($stored === null) {
            throw new RuntimeException('license_missing');
        }
        if (!Crypto::is_credential_envelope($stored)) {
            throw new RuntimeException('license_unreadable');
        }
        if ($expectedRevision !== null && !hash_equals($expectedRevision, hash('sha256', $option . "\0" . $stored))) {
            throw new RuntimeException('preview_stale');
        }
        $key = Crypto::decrypt_credential($stored);
        if (!is_string($key) || !self::valid($key)) {
            throw new RuntimeException('license_unreadable');
        }
        return $key;
    }

    public static function save(string $product, int $siteId, string $key): void
    {
        $option = self::option($product, $siteId);
        $previous = get_option($option, null);
        $intent = Crypto::stage_credential_encryption($key === '' ? '' : self::validate($key), $option, $previous);
        if (!is_array($intent) || ($key !== '' && !Crypto::is_credential_envelope($intent['value']))) {
            throw new RuntimeException('license_storage');
        }
        $written = false;
        try {
            if ($key === '') {
                delete_option($option);
                $written = get_option($option, null) === null;
            } else {
                update_option($option, $intent['value'], false);
                $written = get_option($option, null) === $intent['value'];
            }
        } finally {
            if ($written) {
                Crypto::commit_credential_intent($intent);
            } else {
                Crypto::rollback_credential_intent($intent);
            }
        }
        if (!$written) {
            throw new RuntimeException('license_storage');
        }
    }

    private static function option(string $product, int $siteId): string
    {
        return 'synergetic_products_key_' . str_replace('-', '_', $product) . '_' . $siteId;
    }

    private static function validate(string $key): string
    {
        if (!self::valid($key)) {
            throw new RuntimeException('license_invalid');
        }
        return $key;
    }

    private static function valid(string $key): bool
    {
        return strlen($key) <= 256 && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $key) === 1;
    }
}
