# Validation — JalinWP 0.3.2

Executed 6 September 2026 against this branding update using the existing pinned disposable WordPress/PHP-WASM runtime. No staging, public-site, host, firewall, or GitHub changes were made.

## Completed Checks

| Check | Passed |
| --- | --- |
| OAuth Security | 41/41 |
| Connection And Access Regression | 49/49 |
| YOLO Settings Regression | 37/37 |
| HTTP OAuth And YOLO Lifecycle | 33/33 |
| PHP-Rendered Brand Screens | 52/52 |
| Changed Production PHP Syntax | 10/10 |
| Local Stdio Bridge Regression | 16/16 |

These are separate, overlapping checks, not a combined coverage percentage. Results and hashes are included in the complete source kit under `test-runtime/evidence-0.3.2/`. The HTTP test uses the pinned MCP SDK and synthetic WordPress login/consent with the documented ChatGPT callback shape. It never connects to a real ChatGPT account. It verifies discovery, the bundled logo HTTP response, the exact restricted consent CSP, token exchange, MCP identity, direct draft creation/readback, refresh, and replay rejection. The OAuth security suite covers the registered ChatGPT and Claude callback validation rules.

The brand screen fixture activates the real plugin and WooCommerce, then renders the actual settings header and all four tabs with synthetic settings. It checks navigation, active-tab semantics, bundled assets, the enabled/disabled buttons, finance mapping fields, the Client column, escaping/inert exported forms, and current display labels. It does not simulate an active merchant account or provide visual rendering results.

All ten changed production PHP files passed PHP-WASM syntax checks. The modified HTTP harness passed JavaScript syntax checking and the local stdio bridge passed its existing 16 tests. Source review found no unintended changes to route/issuer identity, stored option/table names, the plugin basename, nonce/capability checks, or execution modes.

## Limits And Staging Review

The browser environment blocked both local HTTP and local-file preview pages. No successful visual desktop/mobile browser inspection is claimed. Sanitized PHP-rendered previews are included under `test-runtime/brand-preview/` for local review. Verify the settings page and OAuth consent at desktop and narrow mobile widths on staging, including logo loading, keyboard focus, tab wrapping, Finance Setup controls, and long account/client names.

This update has not been installed on the user's staging site. Actual ChatGPT/Claude connectivity, the native MySQL/MariaDB upgrade, and the full WordPress/PHP/theme/plugin matrix were not run. The existing minimum-version metadata is retained; it is not a new compatibility certification. Local integration uses WordPress 6.8.8, PHP 8.3 through Playground/SQLite, and WooCommerce 10.2.2 where loaded.

The Canvas label change participates in the existing layout identity hash. Refresh and resubmit pending page-design requests after upgrading; cached expected_state values must be read again. Existing page content and saved Canvas identifiers stay intact. Read Only, Reviewed Changes, and YOLO policies are unchanged.

Packaging verifies ZIP integrity, required assets, the legacy install basename, synchronized version fields, and byte-for-byte equality between installable plugin members and the full source. Prior 0.3.1 tests are archived in [Historical Validation](VALIDATION-0.3.1.md); they are not evidence for 0.3.2.
