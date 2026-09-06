# JalinWP — Improvement and Implementation Plan

Baseline: **0.2.2**. Proposed next release: **0.3.0**, subject to the release gates below.

This document covers all nine requested improvements. It is an implementation specification and handoff, not a claim that the changes have shipped. The current plugin source and the four supplied screenshots were reviewed. The user has confirmed that connections work after changing the GridPane bad-bot setting; this work focuses on the plugin.

Canonical source reviewed: `/workspace/scratch/ee516bb02d35/jalin-mcp-gateway`. Existing test runtime: `/workspace/scratch/ee516bb02d35/test-runtime`. Paths below are relative to the canonical plugin unless otherwise stated. Do not use the older `github/` workspace copy as the implementation baseline.

## 1. Scope and Delivery Order

| Request | Planned Result | Milestone |
| --- | --- | --- |
| 1. Disable button | A visible **Disable MCP Connection** action beside the enabled state; no main gateway checkbox. | B |
| 2. Account setup and messy user list | **Enable for My Account**, a compact account summary, and removal of the bulk user picker from normal setup. Preserve existing explicitly granted access. | B |
| 3. Checkbox explanations | A permanent description beneath every remaining Access Controls checkbox. | B |
| 4. Empty OAuth list | Fix the real listing query, distinguish a database error from an empty list, and show useful connection lifecycle information. | A |
| 5. Finance UX | **Finance Setup** with detected gateway names, optional grouped mappings, clear statuses, and a sample-order test. | C |
| 6. Activity client column | Attribute events to the authenticated OAuth client, with explicit admin, Application Password, and historical labels. | A |
| 7. Title Case | Consistent headings, tabs, buttons, field labels, and table headers; preserve product names and acronyms. | B, then all later UI |
| 8. Page template/layout support | Choose Theme Default, a compatible existing template, or a plugin-owned **JalinWP Canvas** without theme header/footer/sidebar/page-title wrappers. | D |
| 9. Gutenberg design creation | Create and update editable block-based page designs through typed MCP tools and the existing review flow. | E |

Execute A → B → C → D → E → F. A and B establish reliable connection visibility and settings behavior. D establishes layout discovery before E adds complete page authoring. F integrates and validates the whole release. These are milestones within one scope; completing the admin improvements alone does not complete the request.

## 2. Findings From the Current Source

### OAuth Listing Defect

`FG_OAuth::connections()` selects `g.allow_sensitive AS sensitive`. MySQL reserves `SENSITIVE`; the implementation then converts a failed query into an empty array. `FG_Admin::connections()` renders that as “No active OAuth connections.” This can hide valid grants. [MySQL Reserved Words](https://dev.mysql.com/doc/refman/8.0/en/keywords.html)

The local disposable probe exercises the actual listing method with a seeded client and active grant. The original query produces a parser error through WordPress SQLite Integration's MySQL parser. A temporary query filter that quotes only the `sensitive` alias returns the expected connection without an error. The probe passed; no plugin source was modified. This is not a native MySQL or live firz.my reproduction.

Reproduction source: `/workspace/scratch/ee516bb02d35/scripts/test-runtime/plan-oauth-list-probe.php`. Result: `/workspace/scratch/ee516bb02d35/scripts/test-runtime/plan-oauth-list-probe-output.json`. Runner: `node run-wp-tests.mjs plan-oauth-list-probe.php` from the existing test runtime; it creates a disposable copy of the WordPress fixture. The result records `original_query_error_becomes_empty_list: true`, `quoting_only_sensitive_alias_restores_list: true`, and `native_mysql_test: false`. Promote this temporary probe into the maintained OAuth integration suite during implementation.

The earlier admin suite stubs `FG_OAuth::connections()`, and the OAuth suite does not exercise the real listing method. The missing integration assertion is the important coverage gap; using SQLite alone does not explain it.

### Other Confirmed Gaps

- `FG_Admin::quick_connect()` enables the gateway and OAuth and adds the current user. There is no matching quick-disable action.
- `FG_Admin::form()` enumerates the first 200 WordPress users plus selected accounts, creating the clutter shown in the screenshots.
- Connection and Finance use the same full settings form, with the other tab's controls merely hidden. A stale tab can overwrite unrelated newer settings. Every save revokes unfinished approvals, including no-op saves, and redirects to Connection.
- Finance renders blank Stripe and Billplz cards regardless of installed gateways. Existing fee extraction is conservative and should be retained.
- `FG_Core::audit()` does not store OAuth client identity; a new table column alone cannot recover it.
- WordPress content tools accept raw content and list descriptive block metadata. They do not support page template selection or structured block generation.
- The approval queue guards the proposed arguments, expiry, requester, and single execution, but does not guard against a page being edited between proposal and application.

## 3. Milestone A — Connections and Client Attribution

### A1. Repair the Connection List

Select the original `allow_writes` and `allow_sensitive` columns and map their presentation keys in PHP. Avoid reserved SQL aliases. Change the listing result contract to distinguish successful rows from a storage failure. Render **Connections Could Not Be Loaded** and a retry action on failure; do not expose raw SQL, tokens, or database messages.

This query repair needs no grant migration, OAuth reset, or reconnection. Existing valid sessions must remain valid through the upgrade.

Show separate rows for Claude and ChatGPT even when they belong to the same WordPress user. Use **Client**, **WordPress Account**, **Approved Access**, **Status**, **Authorized On**, **Last Authenticated**, **Expires On**, and **Revoke**. Use responsive details for secondary timestamps rather than squeezing every field into a narrow table. Include **Refresh Connections** and keep **Revoke All Connections** distinct from disabling the gateway.

Lifecycle semantics:

| Evidence | Display |
| --- | --- |
| Consent saved; token issuance not yet completed | Approval Received — Finish Connecting |
| Token issuance recorded; no authenticated request yet | Authorized — Awaiting First Request |
| A successful authenticated MCP request occurred | Active, with Last Authenticated time |
| Grant expired or its effective access is invalid | Reconnect Required |
| Grant explicitly revoked | Revoked, where retained history is available |
| Older record lacks lifecycle evidence | Authorization Recorded; do not invent a completion time |

“Active” is authorization/use evidence, not a continuously open network connection. Current `last_used` is updated during authentication, even when a subsequent MCP operation fails. Label it accurately. Do not claim successful tool execution from that timestamp alone.

If lifecycle timestamps are added, use nullable `tokens_issued_at` and `first_authenticated_at`. Backfill only from retained evidence; missing token rows mean unknown history. Reuse grant validity logic without mutating timestamps during an admin listing. Do not rotate the OAuth epoch, issuer, resource, grant IDs, or token hashes during this migration. Revoked/expired history is bounded by retention, not a promise of permanent history.

### A2. Add the Activity Client Column

Resolve client ID/name from the verified OAuth grant inside `FG_OAuth::authenticate()`. Carry it in request-scoped `FG_Auth` context and snapshot it when `FG_Core::audit()` writes an event.

Additive audit fields: `auth_source`, `oauth_client_id`, `oauth_grant_id`, and `client_name`. Use neutral defaults for existing rows. Retain the existing user, operation, outcome, change ID, timestamp, and retention policy.

| Event Origin | Client Column |
| --- | --- |
| OAuth-authenticated request | Registered client display name, such as Claude or ChatGPT |
| WordPress review/settings action | WordPress Admin |
| Application Password request | Application Password |
| Historical event without attribution | Not Recorded |

Registered names are client-provided display labels, not proof of provider identity. Escape and bound them. Do not infer a client from IP, User-Agent, `clientInfo`, or the WordPress account's previous activity. Reset context reliably between requests.

For an admin approval, show the actor as WordPress Admin. Related details may say **Requested Via Claude**, taken from the originating proposal. Store a proposal-context snapshot if needed so the association survives grant cleanup. Never label the administrator's decision as an action performed by Claude.

### A Acceptance Tests

- Real client/grant insertion → real listing → rendered admin rows, without a stub for the listing method.
- Two clients for one account appear separately; refreshing tokens retains the same grant and authorization date.
- Query failure displays an error; genuinely zero rows displays an empty state.
- Consent-only, issued-token, used, revoked, expired, missing-client, and legacy-unknown states are accurate.
- Read, propose, apply, and failure events show the correct client; admin review and Application Password actions do not inherit OAuth context.
- Client names are escaped; historical attribution survives grant/client cleanup.
- Existing tokens survive the upgrade. Schema migration is repeatable and verifies required columns/indexes before advancing its version marker.
- Exercise listing and migrations on native MySQL and MariaDB as well as the existing WordPress test runtime.

## 4. Milestone B — Setup, Access Controls, and UI Language

### B1. A Clear Connection Status Card

Replace the primary gateway checkbox with server-backed actions:

- Disabled: **Enable for My Account**.
- Enabled: an **MCP Enabled** status and **Disable MCP Connection**.
- Gateway enabled but this administrator is not permitted: show both the site status and an **Enable for My Account** action. Do not describe the site as disabled.

The disable button applies to this site's gateway, not just one client. Explain beside it: “Stops MCP access for this site and revokes its OAuth connections. Re-enable and reconnect to restore access.” Clicking it submits the action directly; no separate Save Settings step is needed.

Use POST, a dedicated nonce, `manage_options`, persistence checks, and protection against duplicate submission. Disable access before attempting revocation. Revoke outstanding OAuth requests/grants and unfinished changes. If cleanup fails, keep access disabled and present a useful error; do not show a false success. Re-enabling must not revive previously revoked credentials. Preserve finance mappings, access preferences, origins, and explicitly enabled users.

### B2. Remove the Bulk User Picker

For a new install, enabling permits only the current administrator account. Display **Your Account** with name and effective access. Do not enumerate customers or subscribers during normal setup and do not silently grant every administrator access.

For upgrades, preserve all previously selected user IDs. If additional accounts already exist, show their count and an optional collapsed **Existing Account Access** section containing only those accounts, with explicit removal. No large all-users checklist. Missing form fields must never erase the stored list. A broader team-management interface is not required for this release.

This retains the backend permission boundary while removing the unnecessary UI. Normal WordPress/WooCommerce capabilities still apply, including site membership on multisite. Network activation remains unsupported.

### B3. Descriptions for Every Remaining Checkbox

| Checkbox | Permanent Help Text |
| --- | --- |
| Enable OAuth Sign-In | Allow compatible clients to connect by signing in to WordPress and approving access. Turning this off revokes existing OAuth connections. |
| Allow Reviewed Changes | Allow connected clients to propose edits. An administrator reviews each request in WordPress before the requesting connection can apply it. Requests expire after 15 minutes. |
| Allow Order, Customer, Comment, and Financial Data Access | Allow access to these records and reports, subject to your WordPress and WooCommerce permissions. Leave this off when the connection only needs content access. |

Keep OAuth enabled automatically during standard setup; place its separate checkbox in Advanced Access so the primary screen has fewer overlapping switches. Explain that granting extra access requires renewed client consent, while removing access takes effect immediately. Link descriptions with `aria-describedby`; do not rely on hover-only tooltips.

### B4. Save Behavior and Title Case

Separate Connection and Finance forms and POST handlers. Each modifies only its owned section, merging with current settings. Use a section version/fingerprint and an atomic conditional write or equivalent concurrency control; a read-then-unconditional-update is insufficient. Reject stale conflicting edits with a reload message. Preserve unsaved user input on validation failures.

No-op saves do not revoke approvals. Actual policy or finance changes retain approval invalidation. Return to the tab being edited. Refactor JavaScript initialization so the absence of the connection-check form does not prevent Finance or other modules from initializing.

Tabs: **Connection & Access**, **Finance Setup**, **Review Changes**, **Activity Log**. Headings include **Access Controls**, **OAuth Connections**, **Connection Checks**, and **Advanced OAuth Settings**. Use Title Case in actual UI strings, retaining MCP, OAuth, WordPress, WooCommerce, ChatGPT, Claude, ID, and URL. Explanatory paragraphs remain sentence case. Do not apply CSS capitalization to user names, metadata keys, or other user data.

### B Acceptance Tests

- Enable/disable works in one button action, survives refresh, and accurately reports failures.
- Disable blocks old access and refresh tokens; enable requires fresh authorization after revocation.
- Existing finance settings and other explicit account access survive setup and upgrades.
- No bulk 200-user query/render in normal setup; a current account outside the former first-200 list works.
- Cross-tab and concurrent saves cannot overwrite newer unrelated settings or accidentally re-enable access.
- GET, missing/invalid nonce, and unauthorized actions are rejected.
- Checkbox descriptions, keyboard focus, mobile layout, error feedback, and all static labels are reviewed in-browser.

## 5. Milestone C — Guided Finance Setup

### C1. Explain What Already Works

Start with **Available Automatically**: order totals, taxes, payment methods, customer-added fees, discounts, shipping, and recorded refunds. No metadata mapping is needed for these existing WooCommerce fields; normal access requirements still apply.

Then present optional **Payment Processing Fees** and **Affiliate Commissions**. Explain that processing fees are merchant costs, whereas WooCommerce fee lines are charges on the customer's order. Do not describe stored gateway net as profit or a verified bank payout.

### C2. Configure One Gateway at a Time

List actual registered WooCommerce gateways by display name, with ID as secondary text. Also preserve and display saved mappings for disabled or removed gateways. Remove unconditional blank Stripe/Billplz cards. Provide **Configure**, **Edit**, and **Remove Mapping** actions, and a compact status such as **Not Configured**, **Configured**, or **Needs Attention**.

The selected gateway opens grouped fields for Processing Fee, optional Gateway Net Amount, Settlement Currency, and optional Affiliate Details. Explain units with examples: `12.50 → 12.50`, `1250 → 12.50`, and `12500 → 12.50`. Keep exact metadata keys under **Advanced Mapping**. Until a tested provider adapter supplies verified defaults, make the manual mapping requirement explicit; hiding technical fields must not make configuration impossible.

Do not guess field names from a provider brand. Third-party affiliate tables require an adapter; do not imply an order metadata mapping can read them automatically. Preserve the existing affiliate adapter's precedence and normalized contract.

### C3. Test With an Order

Allow an administrator to select or enter a known order for the chosen gateway, test the unsaved mapping, and inspect normalized results before saving. Show only the requested finance fields, their source keys, units, currency, and statuses: **Available**, **Missing**, **Invalid Value**, **Not Configured**, or **Assumed Order Currency**. Flag a different payment method on the selected order.

Pass the validated unsaved map directly into shared extraction logic. Never temporarily change global settings to preview it. Require POST/nonce, admin and WooCommerce permissions, native per-order authorization, and the sensitive-data policy. Do not dump arbitrary metadata, customer addresses, secret fields, or raw invalid values. Bound order search and response sizes.

Keep **Configured** separate from **Tested on Order #…**. If a test stamp is retained, bind it to the mapping fingerprint and sample order state; editing either invalidates it. A sample result does not establish coverage for every historical order.

### C Acceptance Tests

- Existing custom mappings, gateway IDs, divisors, inactive gateways, and adapter behavior survive the upgrade unchanged.
- Finance saves return to Finance and cannot alter OAuth/access controls; mapping deletion is explicit.
- Preview agrees with `wc_order_financials` for signed amounts, real zero, missing/invalid values, major/minor units, assumed currencies, and different settlement currencies.
- Invalid keys, credential-shaped fields, duplicate gateways, unsupported divisors, and the existing 20-mapping limit produce specific errors.
- Preview causes no option, order, token, or approval mutation.
- Preserve analytics contracts: separate currencies, unknown versus zero, coverage counts, pagination, and recorded-refund semantics.
- Verify both legacy WooCommerce order storage and HPOS, including native database coverage. WooCommerce-inactive state must not produce a fatal error.

## 6. Milestone D — Page Layouts and JalinWP Canvas

Offer page-scoped choices: **Theme Default**, **JalinWP Canvas**, and **Existing Theme Template**. Template selection must also be visible in WordPress so the user can inspect and change it manually.

JalinWP Canvas renders page content without the theme header, footer, sidebar, automatic page title, or constrained theme content wrappers. Implement it in plugin-owned template files, not edits to Kadence or other theme files. Preserve `wp_head()`, `wp_body_open()`, `wp_footer()`, language/body attributes, core block assets, and an accessible main landmark. Scope layout styling to canvas pages. Theme/plugin CSS can still load through normal hooks, so validate the actual appearance rather than promising complete CSS isolation.

For classic themes, register an exact plugin template identifier through `theme_page_templates` and resolve it through `template_include`. Discover eligible theme templates rather than accepting filesystem paths. The Pages REST API exposes a template field. [Template Filter](https://developer.wordpress.org/reference/hooks/theme_page_templates/), [Template Resolution](https://developer.wordpress.org/reference/hooks/template_include/), [Pages REST API](https://developer.wordpress.org/rest-api/reference/pages/)

For block themes, feature-detect `register_block_template()` and register a post-content canvas without header/footer parts. This API starts in WordPress 6.7, while the plugin declares 6.6 support: retain a tested PHP fallback for 6.6. Report the effective template when a theme/user override exists; do not silently advertise an overridden template as blank. [Block Template Registration](https://developer.wordpress.org/reference/functions/register_block_template/)

Retain page content if the plugin is disabled; document that the theme may fall back to its normal layout. Switching a page back to Theme Default restores normal theme rendering. Global theme replacement and editing shared site headers/footers are not needed to fulfill this page-design workflow.

### D Acceptance Tests

- Canvas on Kadence excludes header/footer/sidebar/title and supports full-width content at mobile and desktop sizes.
- Default and existing-template selection work; layout-only updates preserve content.
- Repeat on a standard block theme and the WordPress 6.6 fallback.
- Normal pages are unchanged; required hooks, media, links, and block styles still work.
- Missing/ineligible templates, theme changes, overrides, and plugin deactivation have explicit behavior.

## 7. Milestone E — Editable Gutenberg Page Designs

### E1. MCP Tool Contracts

These are proposed tool names; freeze schemas before implementation and document any final naming changes.

| Tool | Responsibility |
| --- | --- |
| `wp_page_layouts_list` | Discover eligible layouts/templates, identifiers, sources, and compatibility. |
| `wp_page_design_get` | Read page fields, layout, bounded block structure/source, edit links, and an expected-state fingerprint. Clearly disclose truncation. |
| Extended `wp_block_types_list` | Retain discovery; expose bounded supported attributes and an explicit `creation_supported` flag. |
| `wp_page_design_validate` | Validate a structured proposal without writing the page; return normalized data and block-path errors. |
| `wp_page_design_create` | Propose a block-based page and layout; default to draft. |
| `wp_page_design_update` | Propose only supplied fields, requiring an expected-state fingerprint for an existing page. |

Reuse the existing `gateway_change_status` and `gateway_apply_change`. All design writes enter the existing approval queue. Publishing remains an explicit operation requiring native WordPress permissions.

Initial supported blocks must be sufficient for a complete landing page: Group/Row/Stack, Columns/Column, Heading, Paragraph, Image, Cover, Buttons/Button, List/List Item, Separator, and Spacer. Support validated spacing, colors, typography, alignment, nesting, and responsive columns. Use existing media IDs and validated links. Start with finite depth/node/content limits inside the existing proposal size limit and return explicit errors when exceeded.

Unknown or third-party blocks may be discovered and preserved unchanged, but creating/editing them requires a supported adapter. Never silently drop them, convert a whole page to generic HTML, or claim arbitrary installed-block support. A layout-only change must preserve all existing blocks exactly.

### E2. Generate Valid Saved Block Markup

PHP `serialize_blocks()` is not a generator of static block HTML from attributes alone. Gutenberg checks saved markup against the block's JavaScript save output. Use bounded core-block generation adapters with canonical fixtures produced by WordPress block APIs, and validate against the actual installed editor before approval. Successful PHP parsing alone is not proof of a valid editable design. [Block Serialization](https://developer.wordpress.org/reference/functions/serialize_block/), [Block Save and Validation](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/), [WordPress Blocks APIs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-blocks/)

Apply sanitization before freezing the reviewed payload and ensure it does not invalidate block markup. Reject unsafe or unsupported attributes rather than silently changing an approved design. Do not execute arbitrary shortcodes, scripts, PHP, or third-party render callbacks during validation.

### E3. Review, Preview, and Recovery

Show a block outline, changed page fields, layout change, and an authenticated preview tied to the exact proposal. Preview must not update the published page or be publicly accessible. Use isolated rendering of supported content with explicit size limits; do not place untrusted proposal HTML directly into wp-admin.

Include the page's content, layout, relevant metadata, and theme/template identity in a server-generated expected-state fingerprint. Recheck at application immediately before the write. A page edited after proposal must produce **Page Changed — Review a New Proposal**. The approval queue's single-execution claim does not itself lock the page: include a concurrent-editor race test and implement compatible target-write coordination or conflict detection rather than claiming unconditional atomicity.

Capture recoverable content and layout together. Native content revisions alone do not guarantee template metadata recovery; register relevant metadata for revisions where supported and handle disabled revisions explicitly. For a first release, reject updates to existing pages when reliable recovery cannot be established, with guidance to create a draft copy. Partial failures must remain non-retriable until inspected. [WordPress Revisions](https://developer.wordpress.org/reference/functions/wp_save_post_revision/), [Metadata Revisions](https://make.wordpress.org/core/2023/10/24/framework-for-storing-revisions-of-post-meta-in-6-4/)

### E Acceptance Tests

- Through MCP, propose a draft with hero, benefits, image, pricing, and CTA; approve/apply it; open in Gutenberg, edit, save, and reopen without block recovery warnings.
- Test create, layout-only update, full design update, explicit publish, proposal preview, and recovery of content plus layout.
- Reject malformed nesting, unsupported blocks, unsafe attributes, excessive depth/size, stale state, unavailable templates, and expired/revoked approvals.
- Verify native page-edit/publish permissions and preserve unknown blocks in unaffected content.
- Test actual editor serialization/validation on supported WordPress versions, not only PHP round-trips.
- Repeat the end-to-end draft workflow through Claude and ChatGPT on staging, with client attribution visible in Activity Log.

## 8. File-Level Work Map

| File | Work |
| --- | --- |
| `includes/class-oauth.php` | Listing query/error contract, lifecycle evidence, authenticated client identity, compatible migrations if needed. |
| `includes/class-auth.php` | Request-scoped client context and reset; preserve account/capability checks. |
| `includes/class-core.php` | Additive audit schema, verified migration completion, settings concurrency helper if centralized. |
| `includes/class-admin.php` | Status actions, separate save handlers, compact account access, descriptions, connection table, Finance flow, Activity Client column, design review. |
| `includes/class-analytics.php` | Shared validated-map extraction for unsaved preview; preserve financial output semantics. |
| `includes/class-approvals.php` | Design preconditions, proposal client context, exact preview/review payload, target conflict handling. |
| `includes/class-wp-tools.php` | Extend block discovery and optional template projections while preserving existing content tools. |
| New `includes/class-page-layouts.php` | Canvas registration, effective-template discovery, page-scoped template resolution. |
| New `includes/class-page-design.php` | Typed design tools, block adapters, bounded validation and projections. |
| New `templates/canvas.php` and optional `templates/canvas.html` | Classic fallback and block-theme canvas. |
| `assets/admin.js`, `assets/admin.css` | Independent initializers, grouped Finance UX, accessibility, responsive tables and feedback. |
| New `assets/canvas.css` and design validation/review script | Canvas-scoped layout styling and installed-editor validation/preview support. |
| `jalin-mcp-gateway.php`, `readme.txt`, `README.md` | Load modules/assets; update version only with a tested release; document capabilities and compatibility. |
| `tests/oauth.php`, existing admin/security/analytics/WP tests, new design tests | Real integration assertions, regressions, native database and browser checks. |
| `docs/ARCHITECTURE.md`, `docs/ANALYTICS.md`, `docs/OAUTH-DEVELOPMENT.md`, `docs/VALIDATION.md` | Update implementation map, setup instructions, evidence and limitations. |

## 9. Milestone F — Integration, Upgrade, and Staging Release

1. Preserve a 0.2.2 source baseline and fixture snapshot. Confirm the staging WordPress, PHP, database, WooCommerce, and theme versions from non-secret runtime information before finalizing the compatibility matrix.
2. Implement additive, repeatable migrations. Advance version markers only after required columns and indexes exist. No destructive token/grant recreation or guessed historical audit attribution.
3. Run targeted milestone tests plus existing authentication, approval, WordPress tool, WooCommerce tool, analytics, and bridge regressions affected by the changes. Report suite results separately rather than adding overlapping assertion counts into a single coverage number.
4. Run native MySQL and MariaDB listing/migration tests; the prior SQLite fixture is not a substitute. Test declared minimum WordPress/PHP support or explicitly revise those declarations before release.
5. Verify the admin flows in a browser: keyboard navigation, narrow viewport, proper labels, inline errors, successful form returns, and no cross-tab settings overwrite. Verify actual Gutenberg editing and frontend canvas rendering on Kadence and a block theme.
6. Upgrade a fixture with existing grants, settings, mappings, and audit rows. Verify token continuity, sensible legacy labels, and unchanged financial values.
7. Package a staging install ZIP, full source ZIP with build/test instructions and evidence, changelog, and updated MD handoff. Check ZIP integrity, version consistency, and exclusion of fixtures, credentials, caches, and development databases.
8. On staging, verify both clients: list tools, make a harmless read, confirm separate OAuth rows and Client log entries, create/review/apply a draft canvas page, edit it in Gutenberg, revoke a test client, and test gateway disable/re-enable. Do not use real order/payment mutations as the smoke test.

Rollback must preserve content and settings. Before reverting a release that introduced canvas pages, move affected pages to a known available layout or restore the paired content/layout snapshot. Do not delete additive database columns during an emergency plugin rollback. Verify downgrade behavior rather than assuming it.

## 10. Completion Criteria and Evidence Boundaries

The work is complete when all nine requested behaviors pass their acceptance checks and the install/source packages and handoff are available. Fixing the SQL query is the first task, not the end of the work.

Completed during planning: screenshot/source review, architecture investigation, official API checks, and a disposable connection-list defect probe. The plugin itself remains 0.2.2 at this planning stage. No live WordPress, gateway, theme, or server settings were changed during this planning task. No new end-to-end client or native MySQL/MariaDB success is claimed.

Known engineering limits to resolve during execution: installed-editor block compatibility; template recovery when revisions are disabled; native database migration behavior; actual staging theme/plugin interactions; and the narrow race between page-state checking and a concurrent editor write. These must be tested or explicitly bounded in release notes, not hidden behind passing unit tests.
