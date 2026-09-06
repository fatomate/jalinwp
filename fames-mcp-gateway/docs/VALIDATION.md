# Validation — JalinWP 0.3.3

Executed 6 September 2026 against the admin and OAuth cleanup update in the pinned disposable WordPress/PHP-WASM runtime. No live site, host, firewall, or GitHub changes were made.

## Completed Checks

| Check | Passed |
| --- | --- |
| Admin Tabs, Defaults, And Actions | 59/59 |
| Admin DOM Submission Behavior | 8/8 |
| OAuth Connection Cleanup | 16/16 |
| Connection And Access Regression | 49/49 |
| YOLO Settings Regression | 37/37 |
| OAuth Security | 41/41 |
| OAuth Lifecycle And Audit | 26/26 |
| Finance Admin Regression | 62/62 |
| HTTP OAuth And YOLO Lifecycle | 33/33 |
| PHP-Rendered Brand Screens | 66/66 |
| Changed Production PHP And JavaScript Syntax | 5/5 |

These are separate, overlapping checks, not a combined coverage percentage. Current reports and their hashes are included in the complete source kit under `test-runtime/evidence-0.3.3/`. Counts and individual failed-case flags were checked in the saved reports before packaging.

The new admin suite covers the six actual tabs, section separation, correct links and action redirects, POST/nonce/administrator guards, errors, and existing-mode preservation. Fresh Read Only setup was exercised through actual registration, consent, token issuance, and OAuth authentication in the disposable runtime. Enabling a new account does not enable writes or sensitive data access. The DOM suite executes the shipped admin script against actual PHP-rendered forms and checks cancelled/confirmed clear-all, inactive clearing, duplicate submission prevention, and access-tab initialization.

The cleanup suite exercises active and temporarily restricted connection rejection, revoked/expired deletion, dependent credential removal, retained audit/change/settings data, a batch of 106 inactive records beyond the display limit, pending consent cancellation, authorization interleaved after revoke-all, and storage/partial failure behavior. The suite explicitly fails its wrapper if any case fails. The final saved cleanup report is 16/16; an earlier 15/16 development report used a settings comparison that did not account for default normalization. The final test snapshots normalized settings and independently verifies audit and change history.

Existing setup, YOLO settings, OAuth security/lifecycle/audit, and Finance Admin tests passed. Three existing expectations were updated for the intentional new empty-list wording, compact finance heading, and Expired status. Their behavioral assertions remain in place.

The HTTP smoke test uses the pinned MCP SDK for discovery, followed by a synthetic local HTTP login/consent/token flow using the documented ChatGPT callback shape. It checks the current MCP server version, logo HTTP response, restricted consent CSP, direct draft creation/readback, refresh rotation, and replay rejection. No authorization code is sent to a real client callback and no real ChatGPT/Claude account is connected.

Four changed production PHP files passed PHP-WASM syntax checks; the changed admin JavaScript passed Node syntax checking. An independent source review found no actionable issue in cleanup authorization, exact SQL scope, active-credential handling, concurrent issuance, or partial failures.

## Rendered Fixtures And Limits

`test-runtime/brand-preview/` contains sanitized PHP-rendered settings previews for all six tabs, disabled setup, and a synthetic OAuth consent page. The seven admin variants check real markup, navigation, assets, version labels, and inert forms; they are not visual screenshots. The browser environment blocks local HTTP and local-file previews, so no successful desktop/mobile visual inspection is claimed. Inspect header size, tab wrapping, focus, finance summary, and long connection/account labels on staging.

Local integration uses WordPress 6.8.8, PHP 8.3 through Playground/SQLite, and WooCommerce 10.2.2 where loaded. Native MySQL/MariaDB, the full supported version matrix, real TLS, and actual hosted-client reconnection were not run. This update has not been installed on the user's staging site. The full commerce and Gutenberg suites were not rerun because their implementation did not change in 0.3.3.

The plugin basename, REST/OAuth URLs, database schema versions, saved template identifiers, settings defaults, and explicit existing execution modes remain compatible. New behavior is limited to the requested admin organization, presentation, and explicit OAuth cleanup operations.

Packaging checks ZIP integrity, required assets, synchronized version fields, the legacy install basename, install/source member equality, and source hashes. Prior reports in [0.3.2 Validation](VALIDATION-0.3.2.md) and [0.3.1 Validation](VALIDATION-0.3.1.md) remain historical evidence.
