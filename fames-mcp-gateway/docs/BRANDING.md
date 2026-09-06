# JalinWP Branding And Upgrade Notes — 0.3.2

6 September 2026. This release applies the approved JalinWP name, Jalin Weave logo, and Cobalt + Coral palette to the existing 0.3.1 plugin. It is a staging candidate.

## What Changed

- The Plugins list and Settings menu identify the plugin as **JalinWP**.
- The settings header uses the approved horizontal logo on White, with Cobalt and Coral accents. Tabs, buttons, focus states, panels, and Finance Setup use a shared palette and the native WordPress font.
- OAuth approval shows the JalinWP logo, site, requesting client, signed-in account, and actual access. Bundled CSS is inlined; bundled images are allowed from the same origin. Redirect destinations and the consent form policy remain restricted to the validated client.
- MCP initialize identifies the server as `jalinwp`, with display title `JalinWP`. Protected-resource metadata uses the JalinWP display name.
- The page layout label is **JalinWP Canvas**. Its stored identifier stays `fames-mcp-canvas`.
- The downloaded diagnostic filename is `jalinwp-connection-report.json`; its plugin display name is JalinWP.
- Visible headings and buttons use Title Case, including **Enable For My Account**.

## Install Over The Existing Plugin

1. On staging, upload **jalinwp-0.3.2.zip** through Plugins → Add New → Upload Plugin and replace the installed plugin when prompted.
2. Open **Settings → JalinWP**. Confirm version **0.3.2**, the logo, the selected Change Mode, Finance Setup mappings, and your OAuth connections.
3. Existing connector URLs remain valid. A client may retain the old connector name or icon until you edit its display settings; the server cannot rename a client-owned connection label.
4. Try a read through each connected client. Check Finance Setup, Activity Log, and any draft using JalinWP Canvas.
5. Refresh and resubmit any pending page-design request created before the update. The display label participates in the existing layout identity hash, so stale-state validation rejects an old design context. Saved page content and template identifiers are retained.

The install ZIP intentionally contains `fames-mcp-gateway/fames-mcp-gateway.php`. Keeping the existing basename makes this an in-place update. Do not rename the installed directory or install a second copy alongside it.

## Compatibility Decisions

| Kept Identifier Or Behavior | Reason |
| --- | --- |
| `fames-mcp-gateway/` and bootstrap filename | Existing WordPress activation identity and in-place upgrades |
| `fames-mcp/v1` MCP/OAuth endpoints and issuer/resource URLs | Existing registrations, tokens, connector URLs, and metadata discovery |
| `fg_*` options, tables, hooks, cron, nonces, and `FG_*` PHP classes | Existing settings, history, integrations, and authorization behavior |
| Core database marker `0.3.0`; OAuth schema `3` | Branding requires no schema migration |
| `fames-mcp-canvas` and `fames-mcp-gateway//fames-mcp-canvas` | Saved templates and existing pages |
| Text domain and JavaScript validation global | Existing translation/runtime integration identities |
| Read Only, Reviewed Changes, and YOLO behavior | Brand work does not change permissions or execution policy |

Settings/grants are not reset and no credential-rotation migration is added. The existing plugin checks still apply if credentials expire or the site policy changes. No host, firewall, public-site design, site favicon, GitHub, or client account settings are modified by installing brand assets.

## Where To Change Branding

Paths are relative to the plugin directory.

| File | Purpose |
| --- | --- |
| `assets/brand.css` | Shared exact palette, supporting surfaces, and separate success/warning/error tokens |
| `assets/admin.css` | Scoped settings layout, tabs, controls, responsive rules, keyboard focus |
| `assets/finance.css` | Scoped gateway mapping cards and financial tables |
| `assets/oauth.css` | Standalone consent layout and controls, native system font |
| `assets/brand/jalinwp-logo-horizontal-white.png` | Approved opaque-White horizontal logo used in admin and consent |
| `assets/brand/jalinwp-icon-256.png` | Packaged square brand icon for future product integrations |
| `assets/brand/favicon-32.png`, `favicon.ico` | Brand browser icon assets; consent uses the 32 px PNG |
| `includes/class-admin.php` | Menu/header copy, stylesheet loading, active tab semantics, diagnostic filename |
| `includes/class-oauth.php` | Consent markup, bundled style output, resource display name and image CSP allowance |
| `includes/class-server.php` | MCP server display identity |
| `includes/class-page-layouts.php`, `class-page-design.php` | Canvas display name and tool descriptions; preserve stored identifiers |
| `fames-mcp-gateway.php`, `readme.txt` | Public plugin metadata and release version |

Use Cobalt `#3048FF`, Coral `#FF4D45`, Midnight `#18153F`, and White `#FFFFFF`. White text on Cobalt and Midnight text on Coral are the intended readable brand combinations. Keep semantic warnings/errors distinct from Coral. Styles are scoped to `.fg-wrap` or `.jalinwp-oauth`; do not introduce global `:root`, body, or button overrides affecting the rest of WordPress.

The implementation adopts the guideline’s suggested light surface and secondary text colors for this candidate. Manrope and the proposed tagline are not added. No external fonts, CDN artwork, or tracking requests are introduced. Raster pixels may vary slightly from exact CSS color tokens; editable SVG and transparent masters remain future brand work.

## Development And Evidence

Read [Current Validation](VALIDATION.md) for checks actually run against this release. The complete source ZIP includes the runtime, exact result files, a manifest, and sanitized PHP-rendered previews. Historical 0.3.1 evidence remains labeled separately; it does not establish a pass for this release.

The local browser environment blocked preview URLs, so visual desktop/mobile inspection and live staging OAuth verification remain open. The rendered previews are development fixtures with inert forms and synthetic data, not an interactive WordPress installation.
