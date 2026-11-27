# Separate plugin basenames for Lite and Pro

Lite and Pro will have distinct WordPress plugin basenames for both products. This gives MainWP a stable installed identity for each edition. Admin Suite already has this structure. Unified Shop Extras v1.11.1 uses the same basename in both editions.

Unified Shop Extras Pro retains `unified-shop-extras/unified-shop-extras.php` to carry forward its existing plugin and updater identity. Lite moves to `unified-shop-extras-lite/unified-shop-extras-lite.php`. The product owner is making the basename change in Unified Shop Extras. Add-on provisioning uses the corrected product packages. Add-on-managed migration of existing shared-basename Lite sites is outside the pilot scope.
