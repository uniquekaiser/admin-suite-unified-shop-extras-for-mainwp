<?php
declare(strict_types=1);

namespace MainWP\Dashboard {
    final class MainWP_Credential_Storage
    {
        public static function is_credential_envelope(mixed $value): bool
        {
            return is_string($value) && str_starts_with($value, 'envelope:');
        }
        public static function decrypt_credential(string $value): string
        {
            return $GLOBALS['fixture_keys'][$value] ?? '';
        }
        public static function stage_credential_encryption(string $key, string $option, mixed $previous): array
        {
            $value = $key === '' ? '' : 'envelope:' . count($GLOBALS['fixture_keys']);
            if ($key !== '') {
                $GLOBALS['fixture_keys'][$value] = $key;
            }
            return ['value' => $value, 'previous' => $previous];
        }
        public static function commit_credential_intent(array $intent): void
        {
            if ($intent['previous'] !== null) {
                unset($GLOBALS['fixture_keys'][$intent['previous']]);
            }
        }
        public static function rollback_credential_intent(array $intent): void
        {
            unset($GLOBALS['fixture_keys'][$intent['value']]);
        }
    }
}

namespace {
    use Synergetic\MainWPProducts\MainWP\SavedLicenses;

    require __DIR__ . '/../src/MainWP/SavedLicenses.php';
    $GLOBALS['fixture_keys'] = [];
    $GLOBALS['fixture_options'] = [];
    function get_option(string $name, mixed $default): mixed
    {
        return $GLOBALS['fixture_options'][$name] ?? $default;
    }
    function update_option(string $name, string $value, bool $autoload): void
    {
        if ($autoload || !str_starts_with($value, 'envelope:')) {
            throw new RuntimeException('Invalid stored value.');
        }
        $GLOBALS['fixture_options'][$name] = $value;
    }
    function delete_option(string $name): void
    {
        unset($GLOBALS['fixture_options'][$name]);
    }
    function same(string $expected, string $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException('Incorrect credential precedence or refusal.');
        }
    }
    function failure(string $expected, callable $attempt): void
    {
        try {
            $attempt();
        } catch (RuntimeException $error) {
            same($expected, $error->getMessage());
            return;
        }
        throw new RuntimeException('The requested credential should have been refused.');
    }

    $as = 'admin-suite';
    $uwe = 'unified-shop-extras';
    failure('license_missing', fn () => SavedLicenses::resolve($as, 17, ''));
    SavedLicenses::save($as, 0, 'as-default');
    SavedLicenses::save($uwe, 0, 'uwe-default');
    SavedLicenses::save($as, 17, 'as-override');
    same('as-default', SavedLicenses::resolve($as, 18, ''));
    same('uwe-default', SavedLicenses::resolve($uwe, 17, ''));
    same('as-override', SavedLicenses::resolve($as, 17, ''));
    same('one-time', SavedLicenses::resolve($as, 17, 'one-time'));
    $GLOBALS['fixture_options']['synergetic_products_key_admin_suite_17'] = 'envelope:unreadable';
    failure('license_unreadable', fn () => SavedLicenses::resolve($as, 17, ''));
    SavedLicenses::save($as, 17, '');
    same('as-default', SavedLicenses::resolve($as, 17, ''));
    failure('license_invalid', fn () => SavedLicenses::save($as, 17, 'invalid key'));
    echo "Saved license selection contract: PASS\n";
}
