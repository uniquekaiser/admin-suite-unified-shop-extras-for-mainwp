# MainWP Dashboard inventory

Install the development copy as `admin-suite-unified-shop-extras-for-mainwp/admin-suite-unified-shop-extras-for-mainwp.php`. Activate it on a WordPress 6.9 or later Dashboard with PHP 8.3 or later and MainWP Dashboard 6.5 or later. Open **MainWP → Extensions → Admin Suite and Unified Shop Extras**.

The **Admin Suite** and **Unified Shop Extras** tabs show MainWP-visible sites with separate Lite and Pro install and activation states. The edition columns use exact plugin basenames and versions from the last MainWP sync. Unified Shop Extras 1.11.1 and older copies at the shared basename show an unconfirmed edition.

**Refresh status** uses the existing advanced-signed MainWP connection over HTTPS. A refreshed Pro row shows the verified entitlement and update eligibility, with the time it was last confirmed. A failed check retains the last confirmed state for the same active Pro version. A child that lacks the product contract can still report its installed editions and shows upgrade guidance for license status.

Unified Shop Extras needs synced evidence that WooCommerce is active and meets its declared 4.0 minimum. The tab keeps ineligible and unsynced sites visible and hides their action controls. A more recent signed result can also suppress the controls until MainWP syncs again. Admin Suite has independent eligibility. Site actions require MainWP site edit access, and the refresh handler checks the same eligibility and a site- and product-specific nonce before contacting the child.

**Manage site** opens MainWP's own site screen. **Product setup** runs single-site installation, plugin activation, and Pro license activation. [Onboarding guide](single-site-onboarding.md)

## Local checks

- Run `php tests/dashboard-inventory-contract.php` for exact-path inventory, legacy ambiguity, WooCommerce gating, and response validation.
- Run `php tests/mainwp-product-request-contract.php` for product-specific signed request binding.
- Run `php -d extension=zip -d extension=sodium tests/publisher-catalog-contract.php` for publisher catalog verification.
- Exercise both tabs on a disposable MainWP Dashboard connected to a Child over HTTPS. Include Lite, Pro, legacy shared-path and unsynced rows, and sites with active, inactive, missing and unsupported WooCommerce. Try a refresh for each product, a POST with a nonce for another site, and a Dashboard user with narrower MainWP site visibility.

### November 26, 2026 verification

Both tabs passed on a disposable WordPress 7.1.3 Dashboard with PHP 8.3.35, MariaDB 10.11, and MainWP Dashboard and Child 6.5. Authenticated requests opened the extension through its normal admin page and refreshed both products over an HTTPS Child connection. The child ran the Admin Suite and Unified Shop Extras contract candidates with WooCommerce 10.5.2 and fixture license state.

The rendered rows distinguished Lite, Pro, inactive, missing, and shared-path inventory. The Unified Shop Extras tab hid actions with inactive, missing, unsupported or unknown WooCommerce, and after a MainWP disconnect. A simulated connection failure retained the last confirmed license until the next successful refresh. A previously issued nonce could not refresh a site after it became ineligible. A nonce for another site and a subscriber request were refused, and a scope-limited administrator saw only its authorized site.

The PHP 8.2 tests passed for inventory, signed request binding and publisher catalog verification. The WordPress escaping, input and nonce checks passed. The Studio definitions describe separate browser coverage. The local Dashboard checks used authenticated HTTP requests and inspected the rendered HTML.
