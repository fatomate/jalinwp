# JalinWP 0.3.3 — Initial GitHub Release

This is the initial public release from [fatomate/jalinwp](https://github.com/fatomate/jalinwp). It is not an upgrade contract for previously distributed internal copies. The GitHub **tag** is the source identity for the published archives; this file does not mean the tag already exists.

## Install

When the release is published, download **`jalinwp-0.3.3.zip`** and upload it through WordPress **Plugins → Add New → Upload Plugin**. The plugin installs at `jalin-mcp-gateway/jalin-mcp-gateway.php`. The separate **`jalinwp-0.3.3-source.zip`** contains development source, documentation and tests; it is not the WordPress upload ZIP. Verify downloads against the attached **`SHA256SUMS`** once those assets exist.

Requires WordPress 6.6+, PHP 8.1+, HTTPS and working path-based REST routes. Start on staging. Activate JalinWP, open **Settings → JalinWP**, and choose **Enable For My Account**. Setup remains **Read Only**; writes and sensitive data require explicit configuration. **Check Connection** reports discovery/host problems. Account setup and connection checks prepare public discovery metadata through protected actions; opening the settings page does not write it.

The MCP namespace is **`jalin-mcp/v1`** and its URL is `/wp-json/jalin-mcp/v1/mcp`. This public-identity clean break has no old-name route aliases or compatibility layer. Connect each AI client using OAuth and approve the requested access.

## Included

- Self-contained plugin runtime; human guides under `docs/` and development tooling/tests under `scripts/`.
- Read Only, administrator-reviewed changes and explicitly consented YOLO operation, with native capabilities and audit controls.
- Clean plugin, OAuth, admin and Canvas public identity (`jalin-mcp-gateway`, `jalin-mcp/v1`).
- Discovery CSRF fix: settings-page GET no longer publishes `.well-known` files; protected setup/check POST and AJAX remain the writers.
- Repaired fresh fixtures; modernized admin-connection suite replacing the obsolete 0.2.2 failure; strict JavaScript typechecking in CI.
- Install/source ZIP tooling and SHA-256 manifests produced at packaging time.

## Verification And Limits

[Validation](VALIDATION.md) records executed WordPress/PHP-WASM, WooCommerce, Node, DOM, native MariaDB schema, HTTP simulation, syntax/type and packaging checks that have actually been run. [Security Audit](SECURITY-AUDIT-0.3.3.md) records the fixed medium GET filesystem issue and remaining low/informational host, consent and race limits. Those limits are not claimed as code fixes.

Local simulated ChatGPT/Claude OAuth flows are not real hosted-client verification. No live deployment, full supported-version/theme matrix, authenticated browser/editor acceptance, real TLS/proxy validation or native WooCommerce HPOS test is claimed. Extracted-install activation passed 7/7. The release workflow additionally gates publication on formal Standards/Spec review, CI on the exact reviewed commit SHA, and a matching tag and downloaded asset checksums; final publication evidence belongs to the GitHub release and Actions records.
