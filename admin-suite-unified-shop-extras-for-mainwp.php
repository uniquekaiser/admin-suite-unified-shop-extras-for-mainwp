<?php
/**
 * Plugin Name: Admin Suite and Unified Shop Extras for MainWP
 * Plugin URI: https://github.com/uniquekaiser/admin-suite-unified-shop-extras-for-mainwp
 * Description: Manage Admin Suite and Unified Shop Extras across connected MainWP sites.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Tested up to: 7.1.3
 * Requires PHP: 8.3
 * Requires Plugins: mainwp
 * Author: Synergetic
 * License: GPL-3.0-or-later
 * Text Domain: admin-suite-unified-shop-extras-for-mainwp
 */

declare(strict_types=1);

use Synergetic\MainWPProducts\MainWP\Dashboard;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/Release/PublisherCatalog.php';
require_once __DIR__ . '/src/Release/ProductPackage.php';
require_once __DIR__ . '/src/MainWP/ProductRequest.php';
require_once __DIR__ . '/src/MainWP/SiteInventory.php';
require_once __DIR__ . '/src/MainWP/ManagedConnection.php';
require_once __DIR__ . '/src/MainWP/SavedLicenses.php';
require_once __DIR__ . '/src/MainWP/PackageDelivery.php';
require_once __DIR__ . '/src/MainWP/Onboarding.php';
require_once __DIR__ . '/src/MainWP/Abilities.php';
require_once __DIR__ . '/src/MainWP/Dashboard.php';

add_action('plugins_loaded', static function (): void {
    Dashboard::boot(__FILE__);
}, 25);
