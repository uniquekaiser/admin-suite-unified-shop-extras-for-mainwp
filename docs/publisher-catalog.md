# Publisher catalog

The publisher builds one signed catalog from approved Admin Suite and Unified Shop Extras release ZIPs. The public catalog includes Lite download URLs and ZIP hashes. Pro entries contain file manifests for the licensed copies installed on the MainWP Dashboard.

## Publisher workflow

Use PHP 8.2 or later with the Sodium and Zip extensions. Obtain each ZIP and its independently approved SHA-256 from the product release. Run `inspect` to check a package's digest, expected WordPress basename, version, build edition and archive entries.

```sh
php tools/publisher-catalog.php inspect admin-suite lite 3.1.0 admin-suite-lite-3.1.0.zip APPROVED_SHA256
```

Create a local JSON configuration with `revision` and `products`. Include both `admin-suite` and `unified-shop-extras`. This fragment shows the structure for one product. Add the corresponding entry for the other product using its approved artifacts.

```json
{
  "revision": 1,
  "products": {
    "admin-suite": {
      "lite_version": "3.1.0",
      "releases": {
        "3.1.0": {
          "lite": {
            "file": "artifacts/admin-suite-lite-3.1.0.zip",
            "sha256": "APPROVED_LITE_SHA256",
            "url": "https://downloads.example.test/admin-suite-lite-3.1.0.zip"
          },
          "pro": {
            "file": "artifacts/admin-suite-3.1.0.zip",
            "sha256": "APPROVED_PRO_SHA256"
          }
        }
      }
    }
  }
}
```

Relative ZIP paths resolve from the configuration file. Use public HTTPS download URLs with versioned paths. Use a strictly increasing revision when publishing changed metadata. Keep approved versions in `releases` while they should still be eligible as Dashboard Pro sources. `lite_version` selects the Lite release offered for a new install.

Before a product's public Lite download launches, set `lite_version` to `null` and omit the `lite` package entry. Keep the approved Pro manifests. Once a Lite package is published, add its ZIP, approved HTTPS URL and digest, select its version, and sign a higher catalog revision.

Pass an Ed25519 signing seed through a secret file outside the repository. The signer reads a base64-encoded 32-byte seed. The verifier reads a base64-encoded 32-byte public key obtained through the publisher's trusted release channel. Distribute that public key independently of the catalog.

```sh
php tools/publisher-catalog.php build publisher-input.json /secure/signing-seed catalog.json
php tools/publisher-catalog.php verify catalog.json publisher-public-key 1
```

Publish the verified catalog and approved Lite ZIPs. The seed, private Pro ZIPs and local input configuration stay in the publisher's release environment. The signed catalog carries public metadata and manifests only.

The publisher tools built and verified a local signed catalog from the four released ZIPs for Admin Suite 3.1.0 and Unified Shop Extras 1.11.2. This check used an ephemeral key and fixture URLs. A production catalog needs the publisher's signing key and matching verifier pin. Lite can be enabled per product once its versioned WordPress.org download becomes available.

## Product readiness check

Verify the Lite ZIP from the public endpoint and the installed Pro directory against the signed catalog.

```sh
php tools/publisher-catalog.php verify-lite catalog.json publisher-public-key admin-suite admin-suite-lite-3.1.0.zip 1
php tools/publisher-catalog.php verify-tree catalog.json publisher-public-key admin-suite 3.1.0 /path/to/wp-content/plugins/admin-suite 1
```

This checks the installed files at the time of inspection. Dashboard deployment must also verify the product's activation and license state, and verify the packaged bytes against the manifest before offering the ZIP to a child. A Dashboard consumer supplies its trusted key and minimum accepted catalog revision to the verifier.

Unified Shop Extras 1.11.2 ships Lite at `unified-shop-extras-lite/unified-shop-extras-lite.php` and Pro at `unified-shop-extras/unified-shop-extras.php`. The inspector checks the wrapper's edition selector and the build marker in its packaged `uwe-runtime.php`. The older shared-basename Lite build fails the basename check. Both published 1.11.2 ZIPs passed inspection. [Unified Shop Extras 1.11.2](https://github.com/uniquekaiser/unified-shop-extras/releases/tag/v1.11.2)
