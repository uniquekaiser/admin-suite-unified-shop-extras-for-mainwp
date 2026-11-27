# MainWP add-on development plan

## Pilot scope

- Develop the add-on under `GPL-3.0-or-later` in the [public GitHub repository](https://github.com/uniquekaiser/admin-suite-unified-shop-extras-for-mainwp). Run the first pilot on the owner's MainWP Dashboard against Rocket.net staging sites.
- Cover standard single-site WordPress installs in the pilot. Use WordPress 6.9 or later, PHP 8.2 or later, MainWP Dashboard 6.5 and a compatible MainWP Child version. Qualify WordPress Multisite in a separate milestone.
- Provide separate product views, with Lite and Pro discovery and installation for both products. Offer license activation for the Pro edition when the child product calls for it.
- Require active, supported WooCommerce for Unified Shop Extras actions. Keep its installed inventory visible when the requirement is unmet. Admin Suite follows its own requirements.
- Require Dashboard administrator permissions and MainWP target-site edit access for writes. Report when the child blocks a required operation. Fleet and MCP reads follow existing site visibility.
- Prove install, plugin activation, license activation and one saved profile application for each Pro product. Verify the resulting status in the dashboard add-on, Fleet, and read-only MCP. Check Lite installation and the ineligible WooCommerce case alongside the Pro walkthrough.
- Schedule Lite-to-Pro switching, bulk rollout and MCP write actions after the single-site pilot.

## Product editions

| Release packages inspected | Lite plugin basename | Pro plugin basename |
| --- | --- | --- |
| Admin Suite 3.1.0 | `admin-suite-lite/admin-suite-lite.php` | `admin-suite/admin-suite.php` |
| Unified Shop Extras 1.11.2 | `unified-shop-extras-lite/unified-shop-extras-lite.php` | `unified-shop-extras/unified-shop-extras.php` |

Unified Shop Extras 1.11.2 shipped both basenames. The published Lite and Pro ZIPs passed checksum, archive, and edition validation. Lite is available as a GitHub release asset. The add-on pilot excludes migration for legacy Lite installs that share the Pro basename. [Unified Shop Extras 1.11.2](https://github.com/uniquekaiser/unified-shop-extras/releases/tag/v1.11.2)

## Package delivery and licensing

- Verify the signature of one versioned publisher catalog using a pinned verification key. Resolve Lite packages from the approved public URLs and check their ZIP hashes. Resolve the expected Pro file manifest from the same catalog.
- Source each Pro package from the matching active, licensed Pro edition installed on the MainWP Dashboard. Unified Shop Extras also needs active WooCommerce on that Dashboard to operate as a package source.
- Verify the installed Pro files against the publisher's release manifest first. Hash and record the generated ZIP for delivery. Expired or unverifiable Dashboard licensing disables Pro package deployment for that product. Inventory, Lite provisioning and authorized actions on existing child installations remain available.
- Use the existing authenticated MainWP Child connection and Novamira's operator workflow. Offer one-time license entry, an encrypted default for each product, and encrypted per-site overrides. Verify child entitlement independently after activation.
- Use a one-time key when supplied, then the assigned site override, then the product default for a site with no override. Stop an activation if its selected key cannot be decrypted or the provider rejects it. Use MainWP encryption and include its encryption key files in the backup and restore instructions.
- Use each product's existing license activation rule for the managed site's WordPress Administration Email. Validate that address before contacting the provider.
- Use Novamira's single-site onboarding pattern. Plan Lite setup as an Add Site choice and reserve Pro package deployment and license activation for explicit actions once the managed site is connected.

## Managed-site operations

- Use a versioned product contract for status and licensing over the authenticated MainWP Child connection. Reuse the product settings Abilities. Discover supported operations before offering an action and show upgrade guidance when an older build lacks it.
- Admin Suite v1 uses MainWP's advanced-signed `extra_execution` request. The Dashboard binds the operation and submitted license to the signed nonce. Admin Suite requires HTTPS and the site's existing WordPress permissions. The local transport and write passed with MainWP 6.4.2 and 6.5 using a synthetic provider. The connected Herbivore Rocket.net staging child already has active Admin Suite Pro entitlement. Real test-seat activation requires an unlicensed Rocket.net staging child.
- Unified Shop Extras v1 uses the same signed transport in its own request namespace. The product reports its actual edition and WooCommerce eligibility. License activation requires the Pro distribution, active WooCommerce, HTTPS, and the required child permissions. The handler reuses Unified Shop Extras' own provider and protected registration. This handler is in development and needs a product release before the hosted pilot.
- The Unified Shop Extras candidate passed signed Lite and Pro status, Pro activation, and independent entitlement readback on disposable MainWP Dashboard and Child 6.5 with WooCommerce 10.5.2. The test used a synthetic Elite Licenser provider and also verified that activation is unavailable on Lite and when WooCommerce is inactive. A real Unified Shop Extras test seat and an unlicensed Rocket.net child are still required for hosted acceptance.
- A hosted Admin Suite check confirmed signed delivery and child dispatch on MainWP 6.5. The child could not reach Elite Licenser over HTTPS, and a separate readback showed no registration or entitlement. A signed read-only probe confirmed provider reachability from an alternate pilot target. Successful provider activation and the Rocket.net pilot remain open.
- Show the next available action. Record install, WordPress plugin activation and license activation separately. Resume from confirmed child state after a failure. On timeout, check the current state before offering a retry.
- Keep 90 days of operation history with actor, managed site, product, operation, outcome and time. Exclude license keys and raw request and response payloads.

## Profiles and status

- Capture a selection of supported settings for one product from a reference managed site. Use product-defined portable fields and exclude credentials and site-specific identifiers.
- Preview the proposed changes against the target. Check target compatibility and freshness before applying. Ask for a fresh preview when the target state or its capabilities change.
- Demonstrate one reversible module setting per product on staging. Read back the changed value, verify its effect on the target and test that an outdated preview is refused.
- Use one status source owned by the add-on. Fleet displays cached status with links to the add-on. The MainWP MCP Bridge exposes read-only status scoped to the operator's site access. Refresh after successful operations, on normal sync and on explicit request. When a child is unreachable, display the failure and when its last confirmed status was checked.

## Implementation order

| Milestone | Work |
| --- | --- |
| 1. Product readiness | Verify the corrected Unified Shop Extras release packages. Add product-side status and license contracts where required. Publish Lite ZIPs and the signed catalog for both products, including the matching Pro file manifests. |
| 2. Dashboard inventory | The two product tabs read scoped MainWP inventory and provide per-site signed license refresh. Unified Shop Extras hides actions when WooCommerce eligibility is missing. Older product builds retain their inventory and show upgrade guidance when a signed status lookup has no contract. [Dashboard inventory](dashboard-inventory.md) |
| 3. Single-site onboarding | The product tabs install verified Lite and Dashboard-sourced Pro packages, activate each edition separately, and activate child licenses using encrypted defaults and overrides. Lite selection in MainWP's Add Site wizard remains open. [Single-site onboarding](single-site-onboarding.md) |
| 4. Profiles and integrations | Capture and apply reviewed product profiles. Connect Fleet's cached status and the read-only MainWP MCP Bridge views to add-on status. |
| 5. Pilot and public release | Publish the first public installable add-on ZIP on GitHub with Lite downloads deferred. Qualify the provisioning workflow on Rocket.net staging and enable Lite downloads through the signed catalog after the products launch on WordPress.org. |

## Rocket.net staging acceptance

| Check | Pass condition |
| --- | --- |
| Product readiness | Both products have verified Lite and Pro package identities. Public Lite ZIP hashes and Pro file manifests match the signed catalog. A mismatched manifest or expired Dashboard license blocks the affected Pro deployment. |
| Installs and eligibility | Each Lite and Pro edition can be installed and separately activated on a clean eligible site. A compatible non-WooCommerce site still offers Admin Suite actions and shows why Unified Shop Extras actions are unavailable. |
| Licensing and resumption | Verify child entitlement for both Pro products with approved pilot keys. Exercise saved defaults, overrides, one-time entry and a refused credential. Confirm that reopening an interrupted workflow resumes from the verified child state. |
| Profile changes | Apply the settings below, confirm the stored value and what the operator sees, and restore the previous value through a fresh preview. Refuse a stale preview. |
| Fleet and MCP | Confirm the add-on, Fleet and the read-only MCP Bridge agree on edition, activation and license status for in-scope sites. Confirm that an unreachable child shows its connection failure and last verified time, and that out-of-scope sites stay unavailable to the operator. |
| Release | Record the staging outcomes for both products before publishing the public add-on ZIP. |

### Profile settings for the pilot

- **Admin Suite Pro Content Duplicator.** Change `enable_row_actions` to `false` on a site where the module is enabled and the action is visible. Confirm that the Duplicate action disappears from a Posts or Pages row. [Admin Suite settings schema](https://github.com/uniquekaiser/admin-suite/blob/v3.1.0/modules/class-as-content-duplicator.php#L832-L850)
- **Unified Shop Extras Pro Product Presentation.** Change `sale_price_polish` to `true` on a site where WooCommerce and the module are active. Use a product on sale whose presentation is handled by this module. Confirm the styling on the storefront. [Unified Shop Extras settings](https://github.com/uniquekaiser/unified-shop-extras/blob/v1.11.2/modules/class-product-presentation.php#L16-L55)
