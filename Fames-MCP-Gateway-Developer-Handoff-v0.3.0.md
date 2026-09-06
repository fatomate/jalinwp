# Fames MCP Gateway — Developer Handoff v0.3.0

Prepared 5 September 2026. This document accompanies the **0.3.0 staging candidate**, install ZIP and complete source ZIP. It records implementation decisions, file locations, verification and limitations so another Codex agent can continue without reconstructing the project from screenshots.

## Project Context

Fames MCP Gateway exposes typed WordPress and WooCommerce tools through a site-hosted MCP HTTP endpoint with built-in OAuth. The existing plugin supports content, taxonomy, media metadata, comments, products, variations, coupons, orders, customer reads and detailed sales analysis. Changes require administrator review in WordPress before the originating connection applies them. OAuth avoids requiring WordPress Application Password setup for hosted clients; the optional local Node bridge remains available.

The user originally reported ChatGPT/Claude discovery and registration failures on GridPane. Their logs showed the host's 7G bad-bot rule blocking requests before PHP. The user then disabled that rule and reported successful connectivity. Version 0.3.0 focuses on the requested plugin improvements. No hosting configuration, remote WordPress installation or GitHub repository was changed during this implementation. The GitHub repository remains deferred.

The working baseline was the existing **0.2.2** plugin. Its install/source archives remain historical references. The intended upgrade replaces that plugin in place; it does not introduce a second gateway or change the MCP URL or OAuth issuer.

## What Changed

| Request | Implemented Behavior | Main Files |
| --- | --- | --- |
| One-click disable | Disable MCP Connection immediately persists disabled access and revokes OAuth credentials; enabling again cannot revive credentials after failed cleanup. | `class-settings.php`, `class-admin.php`, `class-core.php` |
| Simpler account setup | Enable for My Account uses Title Case. A compact account summary replaces the bulk user checkbox grid. Existing allowed accounts are retained under advanced access; other administrators enable their own accounts. | `class-admin.php`, `assets/admin.*` |
| Checkbox explanations | Each visible access checkbox has its own associated help. OAuth is in advanced connection settings; the gateway checkbox is replaced by buttons. | `class-admin.php` |
| Empty OAuth list | Repaired connection SQL, explicit read-error display, accurate lifecycle states, effective versus consented permissions and revocation controls. | `class-oauth.php`, `class-admin.php` |
| Finance UX | Automatic metrics explained first, installed/saved gateways listed, grouped optional mappings, units examples, unsaved order preview and explicit removal. | `class-finance-admin.php`, `assets/finance.*` |
| Activity client | Client column derives from verified OAuth identity. Administrator, Application Password and legacy events have explicit labels. | `class-auth.php`, `class-core.php`, `class-admin.php` |
| Title Case | Headings, tabs, buttons, diagnostic presentation and OAuth consent labels updated. Diagnostic wire labels remain stable. | Admin, OAuth and frontend assets |
| Page layouts | Page-scoped template discovery, Theme Default, Fames Canvas and eligible existing templates. Canvas omits theme wrappers. | `class-page-layouts.php`, `templates/canvas.*`, `assets/canvas.css` |
| Gutenberg designs | Thirteen explicit core-block adapters, design inspection/validation, reviewed create/update tools, isolated preview, stale-edit protection and paired revision recovery. | `class-page-design.php`, `class-design-blocks.php`, `class-design-review.php`, `class-approvals.php` |

## Upgrade and Staging Use

1. Back up staging files and database. Upload `fames-mcp-gateway-0.3.0.zip` through WordPress Plugins and replace the existing plugin. The complete source ZIP is a development kit, not the install ZIP.
2. Open Settings → Fames MCP Gateway. Existing enabled accounts, access options, finance mappings and resource-bound OAuth grants are preserved after a healthy upgrade.
3. Check OAuth Connections. Existing credentials do not need a forced reconnect merely for the update. Historical timestamps or client attribution that were never recorded remain unknown. If a client needs expanded permissions, reconnect and consent to them.
4. Start fresh change requests: unfinished legacy approvals without the new server context are revoked during migration. A settings change also invalidates unfinished requests; saving unchanged values does not.
5. Test a read from each client. Confirm Activity shows the client. Use Disable MCP Connection, verify reads fail, enable again and reconnect a test client. Revoking one connection should leave another grant usable.
6. In Finance Setup, select a gateway, enter verified metadata keys and units, and use Test With This Order. Check zero, missing data and currency separately before Save Mapping.
7. Ask the client to create a draft using Fames Canvas and supported Gutenberg blocks. Open Review Changes, inspect the preview and field changes, wait for Gutenberg validation, approve, then have the same connection apply the change. Open the draft in Gutenberg and preview the frontend.
8. For an existing test page, change only layout, confirm content is preserved, then restore the returned revision. Also edit a page manually after a proposal and verify that applying the stale proposal is refused.

Do not treat a site-side diagnostic pass as evidence that a hosted client completed its connection. This release was not deployed to the user's staging site from this workspace.

## Runtime Architecture

The bootstrap loads modules and registers hooks. On `rest_api_init`, fixed tool handlers and OAuth/MCP routes are registered. An MCP request is authenticated by `FG_Auth`: HTTPS, allowed account, Origin, consent, native capabilities and rate limits apply. `FG_Server` handles the JSON-RPC envelope and protocol negotiation. `FG_Tools` validates tool arguments, exposes only the visible catalog and sends native WordPress/WooCommerce operations through fixed internal REST paths.

OAuth performs metadata discovery, exact supported callback registration, native WordPress login/consent, authorization code exchange with S256 PKCE, opaque access tokens and rotating refresh credentials. Grant identity stays stable through refresh. Effective access is the intersection of consent, current gateway policy and current WordPress permissions. Access tokens authorize this gateway only.

Mutating tools first create a local proposal. The proposal binds its arguments and server context to the WordPress user, credential/grant, policy revision and expiry. An administrator reviews through a nonce-protected POST. The originating connection calls `gateway_apply_change`; a conditional status transition claims the request once, permissions are checked again, and audit metadata records the result. A failed or uncertain write is not automatically retried.

## Storage and Migration

The core schema marker is `0.3.0`; the OAuth schema marker is `2`. Database names use the current WordPress site prefix. Existing settings and OAuth resource identity remain unchanged.

| Storage | Additions and Rules |
| --- | --- |
| `fg_settings` option | `_policy_revision` binds proposals to a policy generation. `_oauth_revoke_pending` fences incomplete credential revocation. Section fingerprints are derived from fresh values. |
| Changes table | Nullable `server_context` contains versioned server-owned policy/client/design facts. The digest covers arguments and context. Old pending/reviewing/approved rows with NULL context become revoked. |
| Audit table | `auth_source`, `oauth_client_id`, `oauth_grant_id`, `client_name` and a client/time index. Historical rows are not assigned invented identities. |
| OAuth grants | Nullable `consent_recorded_at`, `tokens_issued_at`, `first_authenticated_at`. Existing fields/hashes/epochs remain intact. |

`FG_Core::schema_ready()` verifies required columns and ordered indexes before markers advance. If migration fails, its marker stays old and the upgrade can retry. Required native MySQL/MariaDB verification has not run in this workspace; the source includes a guarded disposable native harness.

Settings forms render a matched snapshot and version from one database read. Writes acquire an atomic option-row lock, read fresh state, reject stale same-section changes and use a binary conditional update. Finance applies one mapping delta within that boundary, preserving unrelated settings/mappings. The lock is short-lived and uses a unique owner token. Cache entries are invalidated after writes.

Disabling commits disabled access before cleanup. If epoch/grant revocation fails, a pending marker remains and re-enabling must successfully revoke old credentials first. Deactivation uses the same path and aborts if disabled state cannot be persisted. This closes a reproduced credential-revival bug. Do not replace these operations with a plain `update_option()` toggle.

## OAuth List Bug and Activity Attribution

The old list query used the unquoted alias `AS sensitive`; `SENSITIVE` conflicts with MySQL syntax. A disposable WordPress SQL-parser reproduction returned no rows until the alias was corrected. The new query selects original columns and maps presentation names in PHP. A database read error returns `WP_Error` and displays an error, distinct from a successful empty result. This establishes a local defect, not a native production diagnosis.

The list separates Approval Received, Authorized, Active, Reconnect Required, Revoked and legacy Authorization Recorded. First-authentication is recorded only when observed; a prior last-used timestamp cannot establish the original first use. The list is bounded to 100 retained grants and reports gateway observations, not a live connection poll of the provider.

Client IDs/grant IDs come from verified credentials, never tool arguments or User-Agent. A client name is an escaped display label supplied at registration, not proof of provider ownership. Names are captured as bounded audit snapshots so deletion or renaming does not rewrite activity history. Application Password and WordPress Admin events are distinct; old unattributed events show Not Recorded. No tokens, passwords or response bodies are added to audit rows.

Request identity is cleared after the outer MCP callback, while nested internal REST calls retain the originating identity. WordPress may invoke permission callbacks again while producing Allow headers; a request-keyed WeakMap reuses only the completed permission decision and does not restore identity or consume another rate slot.

## Finance Setup and Analysis

Automatic WooCommerce metrics remain automatic: totals, discounts, shipping, taxes by rate, customer-added fee lines, payment method and recorded refunds. Optional mappings cover merchant processing fees, gateway net amount/currency and affiliate ID/commission/currency. Exact metadata keys must be verified for the installed provider; no keys or provider API compatibility are guessed.

Preview and analytics use the shared `FG_Analytics::mapped_finance()` extraction. The preview requires administrator and WooCommerce permissions, enabled sensitive-data access and native permission for that order. It evaluates unsaved fields without changing mappings or the order. It exposes only selected normalized values and status, with distinct zero/missing/invalid/unconfigured outcomes. It does not expose arbitrary order metadata, contact data or malformed raw values. Separate affiliate tables need the existing site adapter filter.

Analytics definitions are unchanged: created-order cohorts, up to 31 days, 50 orders per page, default processing/completed status, currency-separated summaries and lifetime recorded refunds on those parent orders. Fetch every page before treating a result as a period total. Gateway net is not profit or verified payout; this plugin does not reconcile provider/bank accounts. See `docs/ANALYTICS.md` for complete metric definitions.

## Page Design and Recovery

New tools are `wp_page_layouts_list`, `wp_page_design_get`, `wp_page_design_validate`, `wp_page_design_create` and `wp_page_design_update`. The design-get response supplies `expected_state` for updates and bounded source windows. The page's content, fields, metadata and effective template identity participate in stale-state checks.

Supported adapters: Group, Columns, Column, Heading, Paragraph, Image, Cover, Buttons, Button, List, List Item, Separator and Spacer. Attributes are explicit and block-specific. Designs allow up to 100 total nodes, eight levels and 60 KB of input/compiled markup. Images reference existing readable attachments. Text is plain text. Unsupported blocks, HTML/script injections, shortcodes, arbitrary CSS and unknown attributes are refused.

Server normalization produces the markup stored for review. WordPress's installed JavaScript parser/validator checks the exact saved blocks before approval, so server serialization cannot quietly create invalid editor content. The digest submitted by the admin form is a review UI gate tied to that content, not a separate OAuth credential. The nonce and administrator capability remain the authorization boundary.

Fames Canvas is page-scoped. Classic themes use a plugin PHP template; supported block themes use `register_block_template()`, with a PHP fallback on WordPress 6.6. WordPress's template API was introduced in 6.7. Theme/user overrides are reported. Standard head/body/footer hooks remain, so other assets can still influence the page. The plugin does not edit theme code or offer Elementor/Divi/Kadence-specific builder adapters. [WordPress Template API](https://developer.wordpress.org/reference/functions/register_block_template/)

Updates require at least two retained revisions and transactional page/meta storage. Native writes require InnoDB, lock the target page/meta, repeat preconditions, save paired content/layout recovery state, perform the native REST update and verify persisted content/layout before commit. A failure rolls back the page transaction and leaves the request non-retriable. WordPress hooks may have external effects outside this transaction. The result identifies a recovery revision; the plugin includes template metadata in WordPress revisions. [WordPress Revision Metadata](https://developer.wordpress.org/reference/hooks/wp_post_revision_meta_keys/)

Layout-only updates omit `blocks` and preserve existing content exactly, including unsupported blocks. Structured content replacement is explicit. The isolated preview is approximate and does not run theme rendering or proposal scripts; open the draft to verify final appearance. Gutenberg's own validator is the editor-compatibility gate. [WordPress Block Validation](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-blocks/#validateblock)

## File Map for Future Changes

Paths below are relative to the `fames-mcp-gateway/` directory in the source ZIP.

| File | Change Here For |
| --- | --- |
| `fames-mcp-gateway.php` | Version, module loading, hooks and tool registration. |
| `includes/class-core.php` | Schema, marker verification, activity storage, activation/deactivation and retention. |
| `includes/class-settings.php` | Fresh snapshots, locks, policy revision, enable/disable and section-specific mutations. |
| `includes/class-admin.php` | Setup, access, account summary, connection table, activity and review forms. |
| `assets/admin.js`, `assets/admin.css` | Setup interactions, help layout, Title Case diagnostic presentation. |
| `includes/class-oauth.php` | Discovery metadata, registration, consent, code/token lifecycle, listing and revocation. |
| `includes/class-auth.php` | Verified request identity, consent/capability boundary, Origin and rate controls. |
| `includes/class-server.php`, `includes/class-tools.php` | MCP transport, tool catalog, input schemas and fixed dispatch. |
| `includes/class-approvals.php` | Immutable context, review, expiry, ownership and single-use apply. |
| `includes/class-finance-admin.php`, `assets/finance.*` | Mapping forms, preview projection, units/status presentation and mapping actions. |
| `includes/class-analytics.php` | Financial extraction, adapters, currencies, cohorts and totals. Preserve documented semantics. |
| `includes/class-wp-tools.php`, `includes/class-wc-tools.php` | Existing content/site and WooCommerce tools; avoid raw SQL for orders. |
| `includes/class-page-layouts.php` | Page template discovery, overrides, identity and canvas selection. |
| `includes/class-page-design.php` | Design tool schemas, state, permissions, transactions and paired revisions. |
| `includes/class-design-blocks.php` | Supported adapter attributes and canonical markup. Add installed-editor round-trip tests when extending. |
| `includes/class-design-review.php`, `assets/design-review.js`, `assets/design-validation.js` | Isolated preview, field differences and installed-editor approval checks. |
| `templates/canvas.php`, `templates/canvas.html`, `assets/canvas.css` | Canvas output; retain WordPress hooks and accessible page structure. |
| `includes/class-discovery.php`, `class-connection.php`, `class-connection-trace.php` | Existing public metadata publication, diagnostic checks and opt-in sanitized trace. |
| `bridge/` | Optional local Node stdio bridge using WordPress Application Passwords. |

## Development and Test Method

Work was divided into setup/settings, OAuth/activity, finance and page-design implementations, with independent review of migrations, settings races and revocation. Changes were integrated into the canonical plugin directory. Tests use disposable copies of a WordPress fixture and synthetic accounts/orders; no real payments, refunds or external client authorization codes were sent.

The independent review reproduced and fixed two additional issues: stale cached mappings combined with fresh fingerprints, and credential revival after failed deactivation cleanup. Canonical block tests also exposed literal-entity attribute round-trip differences; those unsupported inputs now receive explicit errors while plain text remains preserved. Tests compare intended attributes as well as markup validity, so a silently changed design cannot pass solely because it parses.

Executed suites and exact counts are in the bundled `docs/VALIDATION.md` and `test-runtime/evidence/`. They cover setup and DOM behavior, OAuth lifecycle/audit, existing OAuth and security cases, WordPress and commerce tools, Finance Setup in legacy/HPOS modes, existing analytics, design normalization, installed WordPress JavaScript save/reopen, design approval/application, diagnostic/trace behavior, synthetic HTTP flows and syntax.

The runtime was WordPress 6.8.8, PHP 8.3.32 via Playground/SQLite, WooCommerce 10.2.2, Node 22, MCP SDK 1.30.0 and jsdom 26.1.0. HPOS tests use a fixture-only SQLite LIMIT compatibility shim. This is not native MySQL/MariaDB validation. The declared WordPress 6.6/PHP 8.1 minima were not tested as a matrix.

The browser's URL policy blocked the local preview fixture. No alternate route or browser workaround was attempted. PHP-rendered UI fixtures and DOM tests were checked, but visual browser behavior and the full installed Gutenberg editing experience still need staging verification. Native PHP/MySQL/MariaDB and container runtimes were unavailable; an ordinary package setup attempt failed due environment permissions. Native migration/listing tests are included under `test-runtime/native-030/` and remain unexecuted.

Follow `test-runtime/DEVELOPER-TESTS.md` to install pinned dependencies, prepare the disposable fixture and rerun each maintained suite. Do not run destructive fixture tests on a real shop. Current evidence is versioned with the source kit; old 0.2.2 outputs are historical, not current proof. Native page transaction/concurrency, theme combinations, actual ChatGPT/Claude, TLS, WAF and login/2FA extensions remain deployment checks.

## Packaging and Continuation

The install ZIP contains runtime PHP, assets, templates, documentation and license. The complete source ZIP adds maintained tests, portable runners, dependency manifests, selected evidence, this handoff and `SOURCE-MANIFEST.json`. Downloaded dependencies, fixture WordPress/WooCommerce, databases, run directories and credentials are excluded. The manifest records SHA-256 for source members and the separate install ZIP; packaging verifies ZIP integrity and byte equality between installed/source plugin files.

Continue from the 0.3.0 source kit. First run native database gates and the staging checks above on the deployment stack. When changing a tool, update its schema, server validation, permissions, approval context where relevant and tests of resulting behavior. When changing a block adapter, validate intended attributes and save/reopen using the target WordPress editor packages. When changing settings or revocation, retain fresh snapshots, conditional writes and the pending-revocation protocol. Do not infer completion from UI badges alone.


---

# Packaged Validation Record — 0.3.0

Executed 5 September 2026 against disposable installations and synthetic accounts/orders. All nine requested improvements are implemented. The user previously confirmed connectivity after resolving the GridPane firewall block. This update was not installed on that site. No GitHub or host settings were changed.

## Executed Checks

| Check | Passed | Scope |
| --- | --- | --- |
| Setup and Settings | 49/49 | Fresh settings, stale writes, disable state, real OAuth rows and HTML escaping. |
| Setup DOM | 10/10 | PHP-rendered fixtures: help, labels, busy states and duplicate submissions. |
| OAuth Connections and Audit | 26/26 | Lifecycle, errors, migration, identity, header probes and failed deactivation revocation. |
| OAuth Security | 41/41 | PKCE, consent, callbacks, resource binding, replay and permissions. |
| Gateway Auth and Approvals | 17/17 | Application Password auth, expiry, tamper, ownership, claims and audit failure. |
| WordPress Tools | 13/13 | Content, taxonomy, media, comments and native permissions. |
| WooCommerce Tools | 24/24 | Unpaid orders, paid-order guards, catalog, coupons and projection. |
| Finance Setup, Legacy | 62/62 | Mapping actions, previews, units, currency, privacy and stale saves. |
| Finance Setup, HPOS | 62/62 | Same cases with the SQLite HPOS compatibility shim. |
| WooCommerce Inactive | 3/3 | Finance failure states without WooCommerce. |
| Analytics, Legacy | 34/34 | Existing cohort, tax/refund, fee/affiliate and currency semantics. |
| Analytics, HPOS | 34/34 | Same analytics cases with the SQLite HPOS shim. |
| Page Design Writes | 18/18 | Native REST, canvas hooks, paired revisions, state checks and rollback. |
| Design Approval Lifecycle | 10/10 | Proposal/review/apply, validation digest, duplicate, tamper, stale and policy checks. |
| Block Adapters, PHP | 38/38 | Schemas, media, escaping, nesting, size and rejection cases. |
| Gutenberg Canonical Markup | 55/55 | Installed WP JS: intended attributes, save/reopen, landing page and template. |
| Design Review DOM | 18/18 | Approval enablement and digest assignment with real WP packages. |
| Connection Diagnostics | 53/53 | Controlled HTTP discovery/challenge results and admin isolation. |
| Connection Trace | 50/50 | Capture bounds, secret projection, retention and protected actions. |
| HTTP: GridPane-Style Claude Callback | 29/29 | SDK discovery, synthetic login/consent, MCP read, refresh and replay. |
| HTTP: Normal ChatGPT Callback | 27/27 | Same synthetic lifecycle through dynamic routing. |
| Local Bridge | 16/16 | HTTPS/auth, protocol, limits, framing, timeout and sanitization. |
| PHP Syntax | 33/33 | Runtime source and included PHP tests; no deployment assertion. |
| JavaScript Syntax | 4/4 | Runtime source and included PHP tests; no deployment assertion. |

Counts represent different, overlapping units; do not add them into a universal coverage figure. Exact files and `release-0.3.0-evidence-index.json` are included in the source kit's `test-runtime/evidence/`.

Runtime: WordPress 6.8.8, PHP 8.3.32 through Playground/SQLite, WooCommerce 10.2.2, Node 22, MCP SDK 1.30.0 and jsdom 26.1.0. PHP lint uses the installed PHP WASM CLI. Declared WordPress 6.6/PHP 8.1 minima were not exercised as a matrix. Each test copied an existing fixture; initial network setup was not repeated.

## Findings Resolved

- The old unquoted SQL alias `sensitive` caused a reproduced WordPress SQL-parser failure. Fields are now mapped in PHP, and database errors are distinct from empty lists. Native database confirmation is pending.
- Matched fresh snapshots and conditional mapping deltas prevent stale cached settings from overwriting newer data.
- Persisting disabled state and a pending-revocation marker prevents old credentials returning after failed deactivation cleanup.
- WordPress Allow-header probes reuse only the completed permission decision, avoiding repeated auth/rate charges and identity leaks.
- Canonical block tests compare intended attributes and save/reopen. Unsupported entity spellings in alt/URL attributes receive explicit errors. A complete landing page survives native REST saving byte-for-byte.
- Recursive design schemas are approximately 6 KB each; nested server validation remains strict.

## Pending Gates and Limits

**Native Database:** Native PHP, MySQL/MariaDB and container runtimes were unavailable. An ordinary package setup attempt failed due environment permissions; no privilege workaround was used. The source includes a 14-case native migration/listing harness under `test-runtime/native-030/`, with syntax/prerequisite checks only. Native concurrent InnoDB writes remain unexecuted. SQLite and the HPOS shim do not establish native database correctness.

**Browser:** The browser URL policy blocked the local preview fixture. No alternate route was attempted. Actual PHP-rendered HTML and WordPress JavaScript were checked through DOM fixtures, which do not establish visual layout, real browser form submission, CSP enforcement or full Gutenberg editing behavior.

**Clients and Hosting:** HTTP tests use the official SDK for discovery and a synthetic HTTP client for later steps. They inspect hosted callback addresses without sending codes to ChatGPT/Claude. GridPane-style mode is a local Node static proxy, not Nginx. TLS, CDN/WAF, login/2FA extensions and actual client accounts require staging verification. No real payment or refund was executed.

**Themes and Recovery:** Classic canvas hooks and block markup were tested. Actual Kadence/theme overrides, WordPress 6.6 fallback rendering and a broader version/theme matrix remain unverified. Page transactions cannot roll back external extension effects; these safeguards do not cover every legacy content/commerce write. Design preview is approximate.

## Staging Acceptance

Replace the existing plugin with the 0.3.0 install ZIP. Verify preserved settings/OAuth rows, client-attributed reads, per-connection revocation and disable/re-enable. Test unsaved finance mappings on known orders. Create a draft canvas page, validate/approve/apply, open it in Gutenberg, save/reopen, inspect the frontend and restore a paired revision on a test update. Confirm an intervening editor/template/policy change rejects a stale proposal. Read `PAGE-DESIGN.md`, `CONNECTING-CHATGPT.md` and the handoff for instructions.

Old 0.2.2 results and the obsolete bulk-user admin suite are historical. The 0.3.0 setup suite supersedes those UI assertions. The source kit's `test-runtime/DEVELOPER-TESTS.md` identifies maintained commands. Final packaging checks validate ZIP integrity, required runtime members, source/install byte identity and SHA-256 manifests; they do not replace staging installation.
