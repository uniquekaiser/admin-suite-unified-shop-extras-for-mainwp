# Changelog

## 0.1.0 - 2026-11-27

- [NEW] View per-site Lite, Pro, and license status on the Admin Suite and Unified Shop Extras tabs. Unified Shop Extras site actions require active, supported WooCommerce.
- [NEW] Refresh product status over the signed MainWP Child connection and retain the last confirmed result when a child is unreachable.
- [NEW] Install Pro from a licensed Dashboard copy that matches the signed publisher manifest. Activate the installed edition in a separate step.
- [NEW] Confirm Pro activation on each child using a one-time key, an encrypted site override, or an encrypted product default.
- [NEW] Verify signed package catalogs even while Lite downloads are pending. Enable Lite installation per product once an approved download is available.
- [SECURITY] Require site edit access for remote operations. Verify delivered packages and expire temporary download links after use.
- [COMPAT] Requires MainWP Dashboard 6.5, WordPress 6.9, and PHP 8.2 or later.
