# Changelog

## 0.2.0 - 2026-11-27

- [NEW] List managed sites and check Lite, Pro, license, and WooCommerce eligibility through the MainWP MCP Bridge.
- [NEW] Use MCP to install or activate either edition one site at a time and activate Pro using a key already saved on the Dashboard.
- [NEW] Read and change Show duplicate actions in Admin Suite Pro and Sale price polish in Unified Shop Extras Pro on child sites running a compatible product build.
- [SECURITY] Require a fresh, single-use preview for MCP writes and refuse changes when the target, package, saved credential, or settings have changed.
- [COMPAT] Declare PHP 8.3 as the minimum required by MainWP Dashboard 6.5.

## 0.1.0 - 2026-11-27

- [NEW] View per-site Lite, Pro, and license status on the Admin Suite and Unified Shop Extras tabs. Unified Shop Extras site actions require active, supported WooCommerce.
- [NEW] Refresh product status over the signed MainWP Child connection and retain the last confirmed result when a child is unreachable.
- [NEW] Install Pro from a licensed Dashboard copy that matches the signed publisher manifest. Activate the installed edition in a separate step.
- [NEW] Confirm Pro activation on each child using a one-time key, an encrypted site override, or an encrypted product default.
- [NEW] Verify signed package catalogs even while Lite downloads are pending. Enable Lite installation per product once an approved download is available.
- [SECURITY] Require site edit access for remote operations. Verify delivered packages and expire temporary download links after use.
- [COMPAT] Requires MainWP Dashboard 6.5, WordPress 6.9, and PHP 8.2 or later.
