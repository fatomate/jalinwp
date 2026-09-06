=== JalinWP ===
Contributors: teamfames
Tags: mcp, woocommerce, automation, ai
Requires at least: 6.6
Requires PHP: 8.1
Tested up to: 6.8
Stable tag: 0.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An MCP gateway with Read Only, Reviewed Changes and YOLO modes for WordPress and WooCommerce.

== Description ==

Version 0.3.3 is a preview for staging evaluation. It supplies built-in OAuth
sign-in for ChatGPT and Claude, typed MCP tools, optional dashboard review, connection
diagnostics, revocation controls and configurable fee/affiliate metadata mappings.
WordPress Application Password authentication and a local stdio bridge remain
available for local clients.

Read README.md for installation, financial definitions, supported operations,
connection compatibility and current limitations. See docs/CONNECTING-CHATGPT.md
for the OAuth setup guide. Hosted fleet management is not included.

== Installation ==

1. Upload the jalinwp-0.3.3.zip install ZIP, replace the existing plugin if present, and activate on staging.
2. Open Settings > JalinWP.
3. Click Enable For My Account. Choose Read Only, Reviewed Changes or YOLO Mode in the Access Controls tab; enable order/financial data as needed, then save.
4. Run Check Connection and copy the connector URL.
5. Create a ChatGPT custom MCP connector with OAuth. Leave Client ID and Client Secret blank.
6. Connect, sign in on your WordPress site, and approve access. Start with a read-only request.
7. Configure verified finance keys when processing fees or affiliate data are needed.

ChatGPT OAuth setup does not require Node.js or an Application Password. An older
failed connector may need to be recreated after the plugin upgrade. Reconnect if
you later enable additional data/write access or YOLO Mode. Existing OAuth grants
keep reviewed access until new consent explicitly approves direct changes. YOLO
executes in the original tool call without WP dashboard approval; client-side
confirmations may still appear. See docs/YOLO-MODE.md. Site-side diagnostics do not prove
that an external ChatGPT connection has completed successfully.

== Changelog ==

= 0.3.3 =
Compact header with a single Cobalt border and version-only display.
Finance Setup summarizes standard WooCommerce data in a compact text block.
Dedicated Connection Setup, OAuth Connections and Access Controls tabs.
Delete revoked/expired connections, clear inactive history, or explicitly revoke and clear all.
Activity history is retained. Fresh setup uses Read Only; saved modes remain unchanged.

= 0.3.2 =
JalinWP rebrand with the approved Jalin Weave logo and Cobalt + Coral palette.
Updated settings, OAuth consent, MCP display identity and JalinWP Canvas labels.
Existing plugin basename, connection URLs, OAuth issuer, settings and grants are retained.
No changes to Read Only, Reviewed Changes or YOLO authorization policies.
Refresh and resubmit pending page-design requests after updating, because layout labels
participate in the existing stale-state checks.

= 0.3.1 =
Read Only, Reviewed Changes and opt-in YOLO Mode replace the write-access checkbox.
YOLO executes supported writes in one tool call without dashboard approval.
Existing OAuth grants retain reviewed/read-only access; new YOLO consent is required.
Mode-aware tool instructions, auditable direct changes and fresh policy checks.
Page design keeps PHP validation and revision/transaction safeguards; YOLO skips
installed-editor validation during dashboard review. Native permissions remain.

= 0.3.0 =
One-click enable/disable, simplified account access, checkbox explanations and Title Case UI.
Fixed OAuth connection listing; lifecycle states and client-attributed activity.
Guided Finance Setup with unsaved order previews and protected concurrent saves.
Page-scoped templates, JalinWP Canvas, and 13 supported Gutenberg block adapters.
Design preview, installed editor validation, stale-page checks and paired revisions.
Existing OAuth issuer/grants are preserved; legacy unfinished approvals are revoked.


= 0.2.2 =
Exact Claude hosted callback support and client-specific consent security policy.
Automatic protected-resource discovery publication for proactive MCP clients,
additional discovery checks, and an opt-in bounded connection trace/report.
The 0.2.1 MCP endpoint and issuer are unchanged.

= 0.2.1 =
Automatic publication of public OAuth discovery JSON for GridPane-style static
routing. Dedicated JSON issuer, protected file ownership and verified discovery
diagnostics. Recreate old failed connectors after upgrading because the issuer changed.
No Nginx configuration changes are made by the plugin.

= 0.2.0 =
Built-in OAuth authorization-code sign-in with S256 PKCE, automatic ChatGPT client
registration, native WordPress consent, rotating tokens and per-connection access.
Three-step connection setup, copyable endpoint, connection checks and grant revocation.
Versioned database setup for upgrades. The existing local bridge remains available.

= 0.1.0 =
Initial staging preview: WordPress content, WooCommerce catalog/orders/customers,
order-cohort analytics, configurable gateway/affiliate mappings, reviewed changes,
activity log and a dependency-free Node.js bridge.
