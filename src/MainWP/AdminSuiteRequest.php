<?php
declare(strict_types=1);

namespace Synergetic\MainWPProducts\MainWP;

require_once __DIR__ . '/ProductRequest.php';

final class AdminSuiteRequest
{
    /**
     * Bind the requested product operation and key to MainWP's signed nonce.
     * Use with an advanced-signed extra_execution request to an HTTPS child.
     */
    public static function bind(array $request, string $operation, string $key = ''): array
    {
        return ProductRequest::bind($request, 'admin-suite', $operation, $key);
    }
}
