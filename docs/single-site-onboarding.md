# Single-site onboarding

Open the product tab under **MainWP → Extensions → Admin Suite and Unified Shop Extras**, then expand **Product setup** on the managed site. The Dashboard must use MainWP 6.5 or later and the managed site must have an HTTPS connection with advanced signing enabled. Unified Shop Extras requires active, supported WooCommerce on the child.

1. Use **Install Pro**, or **Install Lite** when the publisher enables a public download. The edition is installed inactive. The signed Child inventory confirms the exact basename and version.
2. Use **Activate Lite** or **Activate Pro**. The other edition must be inactive. The action verifies that WordPress activated the expected installed version. Edition switching remains a separate operation in MainWP.
3. On an unlicensed, active Pro edition, use **Activate license**. The child must provide the product's versioned MainWP contract and have a valid WordPress Administration Email Address. The Dashboard verifies entitlement in a fresh signed request. Check the current site state before retrying an unconfirmed operation.

## Package configuration

Configure these server-side values in the Dashboard's `wp-config.php`. Keep the signing seed in the publisher's release environment.

```php
define('SYNERGETIC_MAINWP_CATALOG_FILE', '/path/outside/webroot/publisher-catalog.json');
define('SYNERGETIC_MAINWP_PUBLISHER_PIN', 'BASE64_ED25519_PUBLIC_KEY');
define('SYNERGETIC_MAINWP_MIN_CATALOG_REVISION', 1);
```

PHP needs Zip and Sodium. A signed catalog can contain Pro manifests before the public Lite URLs are available. For each Lite edition you enable, the signed catalog includes its public HTTPS URL, version, and ZIP digest. The download must return ZIP bytes without redirecting. Pro requires an active, licensed Dashboard copy with readable registration, current update rights, and an exact version and file match to the catalog. The Unified Shop Extras Pro source additionally requires active WooCommerce on the Dashboard.

For each install the Dashboard verifies the ZIP, stores it in a temporary directory outside the web root, and issues an opaque HTTPS URL to the native MainWP installer. Dashboard and child hosts must be able to reach one another. The URL expires after three minutes, and the Dashboard removes the URL and temporary ZIP when the call finishes. Exclude download query strings from proxy and access logs.

## Saved keys

Set an independent default key on each product tab, or set a site override under **Product setup**. An explicitly entered key is used for a single activation. With the key field blank, the site override takes precedence over the product default. An unreadable override or a provider rejection stops the operation.

Keys are stored using MainWP's credential envelopes and key files. Include MainWP's encryption key directory and the database in the same backup. Restore both before using saved keys. The add-on displays whether a key exists and omits the key, license payload, and package URL from results. Each site's operation history stores a bounded metadata-only record under its MainWP site ID. It keeps the latest 100 entries recorded in the past 90 days and prunes old entries as operations are recorded.

## Pilot readiness

The Dashboard needs the publisher-approved catalog and production verifier pin for package installation. Both live Dashboard Pro sources must match approved manifests. Child installs need product builds that expose the versioned licensing contract before the add-on can activate their licenses. The onboarding controls live in the product tabs for connected sites.

The production pilot remains pending. Lite installation will be enabled after the corresponding WordPress.org download launches and is included in a higher signed catalog revision.

## Local verification

On November 26, 2026, a disposable WordPress 7.1.3 Dashboard and Child ran MainWP 6.5 on PHP 8.3.35 and MariaDB 10.11. The local installer verified the published Lite layouts and built Pro ZIPs from licensed Dashboard candidate copies. For both products the test installed Lite and Pro, confirmed that each install remained inactive, activated the editions separately, and rejected activation while the other edition was active.

With a simulated license provider, an invalid site override blocked activation even when a product default existed. After clearing the override, an encrypted saved default activated each product. A separate signed Child request confirmed entitlement. The saved-license contract covered one-time precedence, unreadable override refusal, and isolation between products. The test signing key and package URLs were local fixtures.

The Dashboard rejected Pro packaging when an unapproved file was present in either source tree. A direct Unified Shop Extras request with a valid site nonce was refused after WooCommerce was deactivated on the child. PHP lint, the focused WordPress security sniffs, and the inventory, signed request, saved license, and publisher catalog contracts passed.
