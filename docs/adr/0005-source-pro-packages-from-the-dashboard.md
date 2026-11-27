# Source Pro packages from the licensed Dashboard installation

The MainWP Dashboard must run the active, licensed Pro edition of a product to supply its Pro package. Sourcing the package from that installation follows Novamira's Dashboard package model and keeps Pro provisioning tied to an entitled installation. Managed-site entitlement is activated and verified separately for each site. [Novamira Dashboard package source](https://github.com/uniquekaiser/add-on-for-novamira-and-mainwp/blob/v0.7.3/includes/class-pro-package.php#L16-L65)

Each publisher release supplies a file manifest for the Pro edition. Verify the installed files against its matching manifest before packaging, then hash the resulting ZIP for delivery. Expired or unverifiable Dashboard licensing blocks Pro package deployment for that product. Inventory, Lite provisioning and authorized actions on existing child installations remain available.
