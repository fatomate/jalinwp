# WPVibe Comparison and Scope

> Branding update: version 0.3.2 uses the JalinWP name and logo. Historical version numbers below describe the existing feature implementation. See [Branding and Upgrade Notes](BRANDING.md) and [Current Validation](VALIDATION.md).

This package is a focused staging preview, not a WPVibe clone or a claim of feature parity. Version 0.3.1 includes built-in OAuth sign-in, structured Gutenberg design and optional YOLO execution. This update still needs native database and actual client validation on the staging host. WPVibe entries below describe its public product claims, not independent verification. Reference pages reviewed September 5, 2026.

WPVibe combines a hosted MCP service with a WordPress connector plugin. The hosted service handles client connections and orchestration; the plugin executes site operations with WordPress permissions. See [WPVibe overview](https://wpvibe.ai/).

| Area | WPVibe public description | JalinWP package scope |
| --- | --- | --- |
| Connection | Hosted connection for multiple MCP clients and sites. | Site-hosted HTTPS endpoint with built-in WordPress OAuth sign-in, automatic ChatGPT/hosted Claude client registration, diagnostics and revocation; optional local stdio bridge. No hosted fleet service. |
| Content | Broad WordPress REST access. | Typed posts/pages, terms, comments and selected media operations. |
| WooCommerce | Works through plugin APIs. | Dedicated product/variation/inventory, order, coupon and selected customer tools. |
| Sales details | Broad API access; no detailed accounting parity assumed. | Order breakdowns, paginated sales analysis, refunds, tax, discounts, customer fee lines and configured finance mappings. |
| Site maintenance | PHP-emulated WP-CLI commands and fleet updates. | Inspection; no arbitrary commands, plugin/theme/core updates or fleet orchestration. |
| Page builders | Native builder playbooks, including Gutenberg and Kadence. | Structured design with 13 bounded core Gutenberg adapters, page inspection and layout discovery. No dedicated Kadence/Elementor/Divi adapters or claim of full builder parity. |
| Theme work | Draft theme clone, preview, publish and backup. | Page-scoped existing templates and JalinWP Canvas; paired page content/layout revisions. No theme-file authoring, draft theme sandbox or theme rollback. |
| Media/intelligence | Stock search, PDF reading, rendered HTML and Lighthouse. | Selected media management; no stock-photo service, PDF extraction or browser-audit worker. |
| Extensibility/UI | Abilities, saved skills and interactive chat panels. | Fixed typed tools, Read Only/Reviewed Changes/YOLO modes and WordPress activity history; no generic abilities bridge, saved skills or chat UI. |

Feature descriptions: [WPVibe features](https://wpvibe.ai/features/).

For Stripe, Billplz and affiliate systems, JalinWP reads explicitly mapped WooCommerce order metadata. Mapping names, numeric units and currencies must match the actual installed extension. Direct provider API reconciliation, payouts, captures, refunds, chargebacks and affiliate-platform synchronization are not supplied or certified. Recorded order totals are not bank receipts or profit.

Next development stages are: verify the full OAuth connection on the user's public HTTPS staging host; validate the ZIP on PHP/MySQL with the site's actual WooCommerce extensions; then select builder, maintenance or multi-site capabilities based on concrete workflows. This release limits automatic OAuth registration to supported ChatGPT callbacks and the exact hosted Claude callback. Broader client registration, CIMD and OpenID Connect are outside its scope. The official WordPress MCP Adapter already supports HTTP as well as STDIO, so it should not be described as local-only. See [its architecture](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/architecture/overview.md).

YOLO Mode executes permitted writes without per-change WordPress dashboard approval after explicit connection consent. Existing OAuth grants remain reviewed until reconnected for YOLO. Native permissions, typed validation, sensitive-data controls and audit recording still apply. See [YOLO-MODE.md](YOLO-MODE.md).
