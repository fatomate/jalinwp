# JalinWP — 0.3.3

![JalinWP](assets/brand/jalinwp-logo-horizontal-white.png)

An open-source MCP gateway for WordPress and WooCommerce.

**Previously Fames MCP Gateway.** This release adds a compact interface, dedicated OAuth/Access tabs, and connection-history cleanup. The plugin directory and connection URLs retain their existing identifiers. See [0.3.3 Upgrade Notes](docs/UPGRADE-0.3.3.md) and [Branding Notes](docs/BRANDING.md).

An installable WordPress plugin that exposes a typed MCP interface for WordPress and WooCommerce. It provides built-in OAuth sign-in for ChatGPT and Claude: copy the endpoint, connect, sign in to your WordPress site and approve access. It also includes a local connection bridge and configurable financial metadata mappings for gateways such as Stripe and Billplz. This is a staging preview, not a full WPVibe replacement.

## What Is Included

| Area | Available operations |
| --- | --- |
| Posts and pages | Find, read source, create drafts, update, explicitly publish/schedule, trash. Gutenberg source is preserved; HTML is sanitized. |
| Categories and tags | List, create, update. |
| Media | Read existing attachments and update title, alt text, caption, description. |
| Comments | Read and moderate, with sensitive-data access enabled. |
| Site inspection | Read runtime, installed plugins/themes, and registered Gutenberg block types. |
| Products | List/read/create/update/trash, prices, inventory, product categories, variable products and variations. |
| Coupons | List/create/update/trash, discount configuration and usage limits. |
| Orders | Search/read, create unpaid manual orders, update permitted fields, change status, add internal notes. |
| Customers | Read registered customer profiles; access is controlled. Guest contacts remain on their orders. |
| Sales analysis | Paginated order-cohort summaries and individual order financial breakdowns. |
| Financial integrations | Exact metadata mappings for processing fee, gateway net amount, currency, affiliate ID and commission. A PHP filter supports affiliate systems with separate storage. |
| Page Design | Discover templates, use JalinWP Canvas without theme wrappers, inspect and validate structured Gutenberg designs, create/update with revision recovery under the selected change mode. |
| Controls | Account self-enablement, WordPress capabilities, Read Only / Reviewed Changes / YOLO modes, separate sensitive-data access, single-use execution, expiry, rate limits and activity history. |

Five page-design tools extend the existing WordPress, commerce and gateway catalog. The visible catalog depends on the authenticated user's role and privacy settings.

## Install

1. Start on a staging copy of your site. Upload **jalinwp-0.3.3.zip** in **Plugins → Add New → Upload Plugin**, replacing the existing JalinWP installation if present, then activate if needed.
2. Open **Settings → JalinWP → Connection Setup**.
3. Click **Enable For My Account**. To stop all MCP access later, click **Disable MCP Connection**. Each additional administrator enables their own account; previously allowed accounts remain available under Advanced Account Access. The quick setup preserves your existing data-access settings and finance mappings.
4. Under **Access Controls**, choose **Read Only**, **Reviewed Changes**, or **YOLO Mode (No Dashboard Approval)**. Enable **Allow Order, Customer, Comment, and Financial Data Access** for orders or sales analysis, then click **Save Access Controls**. Existing OAuth connections need one new consent after enabling YOLO; see below.
5. Click **Check Connection**, then copy the connector URL. In ChatGPT or Claude's app/connector settings, create a custom MCP connection, paste the URL and select **OAuth**. Leave **OAuth Client ID** and **Client Secret** blank for automatic registration.
6. Click **Connect**, sign in on your own WordPress site with an allowed account, review the access and approve it. Start with a read, such as listing products, before trying a draft or unpaid test order.

Requirements: WordPress 6.6+, PHP 8.1+, HTTPS and working path-based REST routes (non-Plain permalinks). WooCommerce is optional; Woo tools appear only when it is active. ChatGPT OAuth setup requires neither a WordPress Application Password nor Node.js. The optional local bridge requires Node.js 22+. Network-wide activation is unsupported; activate/configure separately per site.

See [Connecting ChatGPT](docs/CONNECTING-CHATGPT.md) for upgrade steps, troubleshooting the registration error, and a staging checklist. A successful site-side connection check does not prove that ChatGPT has completed sign-in; test the external connection on your host.

### Connection compatibility

The endpoint is `https://YOUR-SITE/wp-json/fames-mcp/v1/mcp`; use the exact URL displayed by your site. It supports OAuth bearer tokens and WordPress Application Passwords over HTTPS with stateless MCP HTTP JSON responses. Built-in OAuth supplies discovery, automatic client registration and WordPress login/consent for ChatGPT and Claude connections. This release accepts the supported ChatGPT callbacks and Claude's exact hosted callback; it is not a general OAuth identity provider.

Version 0.2.1 automatically publishes public OAuth discovery metadata for hosts such as GridPane when the confirmed document root is writable. It uses a dedicated issuer ending in `issuer.json` so Nginx can serve the discovery document with the JSON MIME type. No server configuration is edited. Open the plugin settings and run **Check Connection** after upgrading.

Recreate the failed ChatGPT connector after upgrading from 0.1.0 or 0.2.0, using OAuth with blank client credentials; the OAuth issuer changed in 0.2.1. The previous connector may retain its old discovery/client state. Do not disable authentication or enter a WordPress password into the client-credentials fields.

Version 0.2.2 adds Claude registration/consent support and automatically publishes the path-specific protected-resource document used by proactive MCP discovery. The issuer and MCP URL from 0.2.1 remain unchanged. Diagnostics now test that discovery path as well as the challenge route. An opt-in connection trace and downloadable report help diagnose failures without logging credentials or shop data. See [Connection recovery](docs/CONNECTION-RECOVERY.md) when local checks pass but a client cannot connect.

The included stdio bridge remains an advanced option for local MCP clients such as Claude Desktop and Cursor; see [bridge/README.md](bridge/README.md). Its Application Password setup is separate from ChatGPT OAuth.

WordPress Application Passwords inherit the user's native WordPress permissions. Gateway restrictions apply to this MCP endpoint; the credential can also access ordinary WordPress/WooCommerce APIs according to its user's role. Give the dedicated user only the permissions you intend to grant.

## How a Change Works

Select one **Change Mode** under Access Controls:

| Mode | What a Write Tool Does |
| --- | --- |
| **Read Only** | Writes are unavailable; permitted reads and reports remain available. |
| **Reviewed Changes** | Returns a change ID and review URL. Approve in WordPress, then ask the same connection to call `gateway_apply_change` once. |
| **YOLO Mode (No Dashboard Approval)** | Validates, records and executes the change in the original tool call, returning its change ID and result. No WordPress dashboard approval or follow-up apply call is required. |

YOLO is opt-in. Upgrading preserves existing behavior: sites with writes off remain Read Only, and sites with reviewed writes enabled remain Reviewed Changes. After selecting YOLO and saving, reconnect each OAuth client once and approve the consent screen that explicitly describes immediate changes. Existing OAuth grants retain their previous reviewed or read-only access until fresh consent. Your AI client may still request its own confirmation.

Every change record is bound to the requesting WordPress user and connection: an OAuth grant ID or Application Password UUID. Token refresh preserves that identity. Records use a single-execution claim and a 15-minute execution window. The same change ID cannot execute twice, but repeating the original write-tool call creates a separate request and can duplicate effects. Inspect an uncertain result before making another write; use `gateway_change_status` if a change ID was returned.

Actual settings changes revoke unfinished changes and advance the policy revision. Switching to YOLO never applies old reviewed requests. Returning to Reviewed Changes or Read Only stops later direct writes; a write already executing or committed is not rolled back by changing the mode. WordPress and WooCommerce hooks can have partial or external effects.

OAuth access remains the intersection of consented access, current gateway settings and native permissions. YOLO does not grant additional tools, sensitive-data access, payment capture or refunds. The optional Application Password bridge uses the selected site mode with its enabled user's permissions and does not have a separate OAuth consent step.

Page-design tools retain strict PHP block adapters, stale-page checks, transactional storage, exact saved-markup checks and paired content/layout revisions for updates. Reviewed Changes adds a dashboard preview and installed Gutenberg JavaScript validation. YOLO skips that dashboard gate; the installed editor may still identify a compatibility issue when the page is opened later. These design protections are not universal order rollback. See [YOLO-MODE.md](docs/YOLO-MODE.md) and [PAGE-DESIGN.md](docs/PAGE-DESIGN.md).

### Order management boundaries

- New manual orders start **pending payment** and use catalog product prices/store currency. Creation does not collect payment.
- Financial edits, quantity changes and address changes are limited to unpaid **pending/on-hold** orders, because WooCommerce can recalculate taxes and totals. Customer notes can be updated independently.
- Status changes can trigger native emails, stock changes and extension hooks. Moving an order to processing/completed does not prove that money was captured.
- This version does not charge payments, issue refunds, delete orders, modify customer accounts or expose payment tokens. Recorded refunds can be analyzed.

## Detailed Sales and Finance Analysis

The `wc_sales_analysis` tool returns currency-separated metrics by payment method, creation day and tax rate, including original order totals, discounts, shipping, taxes, customer fee lines, recorded lifetime refunds, and an after-refund order-total estimate. The `wc_order_financials` tool returns a detailed breakdown of one order.

Each query covers at most **31 days** and **50 orders per page**. Fetch every page with identical filters before calling the result a period total. Defaults are processing/completed orders, selected by **order creation date**. Refunds are all recorded refunds on those parent orders, regardless of refund date; this is not a cash-flow or settlement report. Current status changes may change historical cohorts.

Open **Finance Setup** to see automatic sales fields, then configure optional processing fees and affiliate fields per gateway. Installed and previously saved gateways appear with setup status. Use **Test With This Order** to preview unsaved mappings before saving; keys stay blank until verified on your site. Mapping fields support amounts stored in major units, hundredths or thousandths. Missing fee/commission data is marked unavailable, not silently converted to zero. Different currencies are never added together. Gateway net amounts and processing fees may be stale or incomplete; this plugin does not call the Stripe/Billplz APIs or reconcile bank payouts.

Read **docs/ANALYTICS.md** for metric definitions, integration examples, coverage fields and interpretation limits. Configurable does not mean automatic compatibility with every gateway or affiliate plugin: order metadata can be mapped, while separate provider tables/APIs need an adapter.

Example requests to your AI client:

> Show completed and processing sales from 1–31 August 2026 by Stripe and Billplz. Fetch every page, keep currencies separate, and show fee coverage.

> Break down order 1234 into products, shipping, discounts, customer fees, taxes, refunds, gateway processing fees and affiliate commission. Highlight missing data.

> Find unpaid pending orders from yesterday. Propose correcting the shipping address for order 1234 and wait for approval.

> Create a draft product with SKU DEMO-001, price 97.00 and stock quantity 20.

## What Remains Outside This Release

Hosted fleet control, arbitrary OAuth-client callback registration, Client ID Metadata Documents (CIMD), OpenID Connect, media uploads, gateway API reconciliation, refund execution, WP-CLI commands, arbitrary REST/SQL/PHP access, theme code editing and rollback, plugin/theme installation or updates, dedicated SEO-plugin adapters, visual builder editing/playbooks, external browser audits and stock-image search.

The structured design tools support 13 core block types with bounded, validated attributes. Existing unsupported blocks remain untouched during layout-only changes; they cannot be regenerated through the structured adapters. The legacy content tools still accept sanitized source. Dedicated Kadence/Elementor/Divi layout editing has not been implemented or validated. See **docs/WPVIBE-COMPARISON.md** for the reference feature comparison.

## Operations and Maintenance

- Requests: 60 per minute per WordPress user; bodies limited to 1 MiB. Tool output is bounded; reduce the requested page size when needed.
- Change arguments are stored locally for reviewed and YOLO execution and removed after expiry plus one day. Audit metadata is kept for 30 days. Cleanup depends on WordPress scheduled tasks running.
- Audit entries omit credentials and response bodies. Proposal records can contain supplied content or contact information. The activity log is local and not tamper-proof against database administrators.
- Revoke an OAuth connection in **OAuth Connections** to end that grant's access, or revoke all connections there. Disabling the gateway or OAuth, or deactivating the plugin, revokes OAuth connections. Access tokens last one hour; rotating refresh tokens work within a grant's 30-day lifetime. Reconnect after grant expiry or revocation.
- Revoke an Application Password separately to revoke that bridge connection. Disable the gateway to block all MCP requests. Deactivation disables it and revokes unexecuted changes; it retains plugin tables/settings. Plugin deletion retains those records too, so export or remove them manually if required.
- HTTPS behind a trusted reverse proxy must be configured correctly in WordPress. This plugin does not trust arbitrary forwarded headers to bypass HTTPS enforcement.

## Validation

See **docs/VALIDATION.md** for executed checks and remaining staging requirements. Native MySQL/MariaDB and actual browser/client verification of this update are pending. The separate source kit includes tests and the runtime instructions. The install ZIP excludes test scripts.

For another Codex agent, [OAUTH-DEVELOPMENT.md](docs/OAUTH-DEVELOPMENT.md) explains OAuth consent, per-connection YOLO access, connection internals and where to make changes. It supplements the historical 0.1.0 handoff; that older report's OAuth-absence statements describe 0.1.0 only.

## License

GPL-2.0-or-later. Original implementation for Team Fames; no WPVibe code or assets are included. WordPress, WooCommerce, Stripe, Billplz and WPVibe are their respective owners' names/trademarks.
