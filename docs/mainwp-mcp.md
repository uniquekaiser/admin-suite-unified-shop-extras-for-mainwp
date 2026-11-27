# Manage products through MainWP MCP

Use the [MainWP MCP Bridge](https://github.com/uniquekaiser/mainwp-mcp-bridge) on the Dashboard with a supported WordPress MCP Adapter. The add-on registers the `synergetic-mainwp-products` WordPress abilities namespace with the bridge. In dedicated-server mode the bridge publishes these abilities under `/wp-json/mcp/mainwp`. In existing-server mode, use the adapter's ability discovery and execution tools with the full WordPress ability ID.

Authenticate as a WordPress administrator with access to the MainWP extension. The add-on uses MainWP site visibility and checks edit access before changing a site. The bridge's safe mode and tool policy also apply. The MainWP native Remote MCP catalog is separate from the bridge.

| WordPress ability suffix | Dedicated MCP tool | What an agent can do |
| --- | --- | --- |
| `list-sites` | `synergetic_mainwp_products__list_sites` | List scoped site IDs, Lite and Pro states, cached licensing and WooCommerce eligibility for one product. |
| `get-status` | `synergetic_mainwp_products__get_status` | Read one site's synced inventory and signed live license status when the child supports it. |
| `onboard-site` | `synergetic_mainwp_products__onboard_site` | Install an approved Lite or Pro package, activate an installed edition, or activate the Pro license from a saved key. |
| `get-setting` | `synergetic_mainwp_products__get_setting` | Read the approved setting for one site and product. |
| `update-setting` | `synergetic_mainwp_products__update_setting` | Preview and change the approved setting and verify it on the child. |

Use `admin-suite` or `unified-shop-extras` as the product. For onboarding, request one operation per call with `install_lite`, `install_pro`, `activate_lite`, `activate_pro`, or `activate_license`. Installation uses the [publisher catalog](publisher-catalog.md). Pro is packaged from the active, licensed Dashboard copy. License activation uses the encrypted site override or product default saved in the Dashboard.

Settings use the product's own settings validation and a checksum from the child. This pilot exposes the Content Duplicator **Show duplicate actions** toggle (`enable_row_actions`) in Admin Suite Pro and the Product Presentation **Sale price polish** toggle (`sale_price_polish`) in Unified Shop Extras Pro. The module must be enabled on a managed product build with the signed settings contract. Unified Shop Extras also requires an eligible WooCommerce site.

## Approve a change

1. Call the write ability with the site, product, intended operation or value, and `dry_run: true`. Show the returned changes to the operator and get their approval.
2. Use the same arguments with `confirm: true` and the original `preview_token`, omitting `dry_run`. On the dedicated bridge with confirmation required, this call issues the bridge's `confirmation_token`. Resubmit the same arguments with both tokens and `user_confirmed: true` to apply the change. On a shared server the add-on token and `confirm: true` apply the change.
3. Read status or the setting after the write. If the preview expires or the underlying state changes, request a fresh preview and approval.

The add-on preview expires after five minutes and is bound to the WordPress user, target, operation and observed state. License keys are entered through the Dashboard and stay out of MCP arguments and results. Bulk onboarding and saved profiles have their own development milestones.

## Local verification

WordPress 7.1.3, MainWP Dashboard and Child 6.5, MainWP MCP Bridge 0.5.0, and MCP Adapter 0.7.2 ran in a disposable fixture. The [runtime checks](../tests/mcp-abilities-runtime.php) installed and separately activated Lite and Pro for both products, activated the Pro licenses using saved fixture keys and a simulated provider, and confirmed entitlement through signed Child requests. The checks also refused a subscriber, an out-of-scope site, a replayed or mismatched preview, and Unified Shop Extras operations after WooCommerce was deactivated.

Authenticated MCP HTTP calls discovered all five tools and completed preview, confirmation, signed setting update, and readback for both products on the dedicated bridge. The shared Adapter endpoint also completed an Admin Suite setting change through generic ability execution. Hosted activation and settings tests require the corresponding Dashboard and product builds.
