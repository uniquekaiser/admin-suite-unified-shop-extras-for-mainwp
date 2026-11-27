# Encrypted saved licenses per product

The dashboard follows Novamira's licensing model. Each product has an encrypted default license, optional encrypted overrides for individual sites, and the ability to enter a key for a single activation. This supports newly connected sites and sites that need their own key. Fleet, MCP and operation results expose status and omit license values. [Novamira licensing model](https://github.com/uniquekaiser/add-on-for-novamira-and-mainwp/blob/v0.7.3/README.md#L58)

Resolve the explicit one-time key first, then the assigned site override, then the product default when the site has no override. An unreadable override or provider rejection stops that activation for operator review. Use MainWP encryption and document restoring the database together with its encryption key files. [Novamira encryption](https://github.com/uniquekaiser/add-on-for-novamira-and-mainwp/blob/v0.7.3/includes/class-crypto.php#L12-L55)

Remote activation uses the child product's existing Administration Email rule. Validate the site email before contacting the provider. The operator-selected key determines which license is used for that site.
