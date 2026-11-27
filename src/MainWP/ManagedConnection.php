<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\MainWP;

use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_Credential_Storage;
use MainWP\Dashboard\MainWP_DB;
use Throwable;

final class ManagedConnection
{
    public static function ready(object $site): bool
    {
        return wp_parse_url($site->url, PHP_URL_SCHEME) === 'https'
            && (int) MainWP_DB::instance()->get_website_option($site, 'support_advanced_sign', 0) === 1;
    }

    public static function product(object $site, string $product, string $operation = 'status_v1', string $key = ''): array
    {
        $reply = self::call($site, 'extra_execution', [], static fn (array $post): array => ProductRequest::bind($post, $product, $operation, $key));
        if ($reply === null) {
            return [];
        }
        $envelope = $reply['synergetic_' . str_replace('-', '_', $product)] ?? null;
        if ($envelope === null) {
            return ['outcome' => 'unsupported'];
        }
        return is_array($envelope) ? $envelope : [];
    }

    public static function call(object $site, string $action, array $params = [], ?callable $bind = null): ?array
    {
        if (!self::ready($site)) {
            return null;
        }
        $target = clone $site;
        $target->verify_certificate = 1;
        $filter = static function (array $post, object $website, string $operation) use ($target, $action, $bind): array {
            return $bind !== null && $website === $target && $operation === $action ? $bind($post) : $post;
        };
        add_filter('mainwp_get_post_data_authed', $filter, PHP_INT_MAX, 3);
        try {
            $post = MainWP_Connect::get_post_data_authed($target, $action, $params, ['verify_signature' => true]);
        } catch (Throwable) {
            return null;
        } finally {
            remove_filter('mainwp_get_post_data_authed', $filter, PHP_INT_MAX);
        }
        parse_str((string) $post, $signed);
        if (empty($signed['data_signature']) || empty($signed['mainwpsignature_adv'])) {
            return null;
        }
        $user = MainWP_Credential_Storage::decrypt_credential($target->http_user ?? '');
        $password = MainWP_Credential_Storage::decrypt_credential($target->http_pass ?? '');
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        if (is_string($user) && $user !== '' && is_string($password) && $password !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . $password);
        }
        $response = wp_safe_remote_post($target->url, [
            'body' => $post,
            'headers' => $headers,
            'timeout' => 60,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 4 * 1024 * 1024,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200
            || !preg_match('~<mainwp>([A-Za-z0-9+/=]+)</mainwp>~', wp_remote_retrieve_body($response), $match)) {
            return null;
        }
        $decoded = base64_decode($match[1], true);
        $result = $decoded === false ? null : json_decode($decoded, true, 32);
        return is_array($result) && !isset($result['error']) ? $result : null;
    }
}
