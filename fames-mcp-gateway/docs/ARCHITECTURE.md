# Architecture — 0.3.1

> Branding update: version 0.3.2 uses the JalinWP name and logo. Historical version numbers below describe the existing feature implementation. See [Branding and Upgrade Notes](BRANDING.md) and [Current Validation](VALIDATION.md).

JalinWP is an installable staging preview for one self-hosted WordPress site and its WooCommerce store. Version 0.2.0 adds a site-hosted OAuth authorization server and a simpler ChatGPT setup flow. It does not provide full WPVibe parity. The user reported successful connectivity after resolving the host firewall block. This 0.3.1 update still needs validation on that staging stack.

## Connection and request path

The preferred ChatGPT or Claude path is:

1. The user adds the site's MCP URL with OAuth and blank client credentials.
2. The client discovers the protected resource and authorization server, then registers a client automatically.
3. WordPress handles login. The plugin displays a consent page showing the client and available access.
4. After approval, an authorization code returns to the registered client callback. The client exchanges it with an S256 PKCE verifier for access and refresh tokens.
5. The client sends MCP requests with an OAuth bearer token. The plugin authenticates the grant and checks current settings, consented access and native WordPress capabilities before invoking a tool.

The endpoint is `https://your-site.example/wp-json/fames-mcp/v1/mcp`; use the exact URL displayed by WordPress for installations in a subdirectory. OAuth tokens authorize this gateway only. The plugin does not turn them into general WordPress REST credentials or forward them to Stripe, Billplz or another service.

The optional Node.js stdio bridge forwards JSON-RPC messages over HTTPS using a WordPress Application Password to the same MCP endpoint. It remains useful for local clients; it is not needed for ChatGPT OAuth. Direct HTTP clients supporting the required MCP headers and Application Password authentication can also use the endpoint. No hosted JalinWP service or AI inference subscription is required by this implementation.

## Transport

The transport implements stateless JSON responses over MCP Streamable HTTP. It supports initialization, ping, tool listing and tool calls; accepted notifications receive empty HTTP 202 responses. Authenticated GET/DELETE return 405; unauthenticated requests receive the OAuth challenge before transport dispatch. SSE streams, persistent MCP sessions, resources, prompts and server-initiated requests are not implemented. Protocol negotiation covers 2025-11-25, 2025-06-18 and 2025-03-26. See the [MCP transport specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports).

Unauthenticated MCP requests receive a Bearer challenge pointing to the protected-resource metadata when OAuth is enabled. OAuth metadata, registration and token endpoints are separate from the MCP tool endpoint. WordPress login and the consent form use the site's native login/cookie flow; cookie-only requests cannot call MCP tools.

## Components and authorization

| Component | Responsibility |
| --- | --- |
| `class-oauth.php` | OAuth discovery, automatic client registration, WordPress login/consent, PKCE, codes, token rotation, grant storage and revocation. |
| `class-discovery.php` | Safely publishes public OAuth metadata into the confirmed web root for hosts that serve well-known paths statically. |
| `class-connection.php` | Administrator diagnostics for local configuration, proactive and challenge-led discovery, and unauthenticated GET/POST challenges. |
| `class-connection-trace.php` | Opt-in temporary REST trace, sanitized administrator report and public manual OAuth values. |
| `class-server.php` | JSON-RPC envelopes, version negotiation, payload limits, authentication response headers and tool dispatch. |
| `class-auth.php` | Endpoint-scoped bearer authentication, Application Password identity, HTTPS, user access, Origin validation and request limiting. |
| `class-tools.php` | Strict input schemas, native capability and OAuth-consent checks, fixed internal REST paths. |
| `class-approvals.php` | Frozen change records, reviewed/direct execution separation, administrator decisions and single-use execution claims. |
| `class-wp-tools.php` | Content, taxonomy, comments, media and site/block inspection. |
| `class-wc-tools.php` | Products, variations, inventory, orders, coupons and selected customer data. |
| `class-analytics.php` | Order financial breakdowns and paginated sales analysis. |
| `class-settings.php` | Fresh section snapshots, conditional writes, account enablement, revocation fencing and approval policy revisions. |
| `class-finance-admin.php` | Guided gateway mappings and permission-checked unsaved previews using the shared analytics extractor. |
| `class-page-layouts.php`, `class-page-design.php` | Page template discovery/canvas, expected-state guards, prepared WordPress page writes and paired revision recovery in both write modes. |
| `class-design-blocks.php`, `class-design-review.php` | Strict core-block adapters, frozen markup; isolated preview and installed editor validation for Reviewed Changes. |
| `class-core.php` | Settings defaults, activation/upgrade orchestration, shared tables, rate limiting and retention. |
| `class-admin.php`, `assets/admin.js`, `assets/admin.css` | Setup steps, copyable URL, access controls, diagnostics, connection revocation, financial mappings, reviews and activity. |

OAuth uses authorization code plus S256 PKCE, the `mcp` scope and an exact MCP resource. This release supports Dynamic Client Registration (DCR) with supported ChatGPT callbacks and the exact hosted Claude callback; it does not offer unrestricted redirect registration, Client ID Metadata Documents (CIMD) or OpenID Connect. Access tokens expire after one hour. Grants last 30 days; refresh rotates the token pair without extending the grant beyond its expiry. Opaque secrets are stored as hashes, and the grant's stable UUID identifies the connection across refreshes.

The user's effective tool access is the intersection of:

- The permissions approved on the WordPress consent screen.
- Current gateway settings, including allowed users and data/write switches.
- The current WordPress role and native controller/object permissions.

Enabling additional access later requires new consent. Grants separately record `allow_writes`, `allow_sensitive` and `allow_yolo`; new `allow_yolo` defaults to false for existing grants. Selecting site YOLO mode does not widen their reviewed access. `FG_Auth::write_mode()` intersects fresh settings with the authenticated grant and returns `read_only`, `reviewed` or `yolo`. Subsequent requests observe reduced site access. Reviewed grants require per-operation dashboard approval; YOLO-consented grants can execute directly while the site permits it. See [OAUTH-DEVELOPMENT.md](OAUTH-DEVELOPMENT.md) for lifecycle and change locations.

WordPress also provides [Application Password authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/). Those credentials inherit the user's native WordPress permissions outside the MCP gateway. Use a dedicated account with only the permissions intended for a bridge connection. Input to tools does not accept arbitrary REST paths, SQL, shell commands or PHP execution.

## Change Modes and Execution

The `writes` setting controls write availability and `write_mode` selects `reviewed` or `yolo` while writes are enabled. Existing settings without `write_mode` default to reviewed. Admin radios expose these as Read Only, Reviewed Changes and YOLO Mode. Mode cannot be selected in MCP tool arguments.

`FG_Tools::call()` validates the registered tool, uses the authenticated connection's effective mode, then dispatches a mutating call to `FG_Approvals::propose()` or `execute_direct()`. Catalog descriptions and initialization instructions report that mode; write tools are hidden in Read Only. Native capability and sensitive-data checks remain separate.

Both paths store exact arguments and server-owned context with the policy revision, authenticated client attribution and `execution_mode`; design records also freeze compiled markup and page/layout state. The record is bound to the user, credential and a 15-minute execution window. An OAuth grant UUID remains stable across token refresh; reconnecting creates a distinct grant and does not transfer change ownership.

| Path | Record and Execution |
| --- | --- |
| Reviewed | Starts `pending`, becomes `approved` only through administrator review, then public `gateway_apply_change` executes it once. |
| YOLO | Starts `ready`; `execute_direct()` immediately enters the private execution method in the same tool call. No review or public apply call is required. |

Public apply accepts only approved reviewed records. It cannot execute a YOLO record, and mode changes do not auto-execute old proposals. The direct path captures mode and policy revision from the same fresh settings snapshot, then rechecks fresh site/account/effective connection access before and after its atomic execution claim. Actual settings saves revoke unfinished `pending`, `reviewing`, `approved` and `ready` rows and advance `_policy_revision`; no-op saves preserve them.

Successful execution returns `change_id`, `status`, `execution_mode`, `result` and audit status. Direct execution failures include the generated change ID when storage succeeded. Audit outcomes distinguish `yolo_requested`, `yolo_execution_started`, `yolo_applied` and `yolo_failed_check_site`. A failed execution never becomes automatically retriable.

The claim prevents repeated execution of one change ID. Repeating an original write-tool call creates a new record and can duplicate effects; this is not a distributed exactly-once guarantee. Mode downgrade prevents later/direct work that has not crossed the final execution check. It does not undo a committed write or external effects from a request already executing. Native WordPress/WooCommerce hooks may send emails or alter inventory; inspect uncertain writes before making another request.

Dedicated page-design updates keep strict PHP adapters, expected-state checks, transactional page/meta locking, exact saved-markup verification and paired content/layout revisions in both modes. Reviewed Changes also uses dashboard Gutenberg JavaScript validation. YOLO skips that browser gate, so native editor compatibility can still need correction when the page is opened later. Legacy content/order tools do not gain universal transaction recovery. See [YOLO-MODE.md](YOLO-MODE.md).

## Commerce and finance

Commerce uses WooCommerce REST controllers and CRUD rather than direct order-table writes, following [WooCommerce HPOS guidance](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/). This design supports either storage architecture; deployment validation must still cover the site's installed extensions.

Sales analysis uses explicit order-created date ranges, bounded pages and currency-separated figures. All pages must be combined for period totals. Refunds are recorded lifetime refunds on the selected orders. Gateway fee/net and affiliate values come only from administrator-configured order metadata or a site-owned affiliate adapter. Stripe and Billplz are configurable mappings, not tested direct gateway integrations. Missing values remain unknown. See [ANALYTICS.md](ANALYTICS.md) for definitions and limits.

## Hosting and operational boundaries

Discovery is part of the connection's public surface. Version 0.2.1 uses the stable issuer `rest_url('fames-mcp/v1/oauth/issuer.json')`. Its RFC 8414 discovery path inserts `/.well-known/oauth-authorization-server` before that issuer's path. The resulting filename ends in `.json`, allowing normal Nginx JSON MIME handling. `FG_Discovery` publishes this public document automatically from the authorized settings/check flow when it can verify and write the web root. This allows GridPane-style static well-known handling to serve discovery before WordPress runs. Only plugin-owned, unmodified metadata files are updated; no Nginx settings, other discovery documents or ACME files are changed.

Version 0.2.2 independently publishes the standard protected-resource document at `/.well-known/oauth-protected-resource` followed by the MCP resource path. This supports clients that discover metadata before receiving a 401 challenge. That file is extensionless to preserve the existing MCP URL. The host controls its Content-Type: the tested official SDK parses valid JSON served as `application/octet-stream`, but strict clients may require `application/json`. Diagnostics show an incorrect MIME as a warning and fail missing or mismatched documents. Publication of one document can succeed even if the other fails.

`FG_OAuth::well_known()` serves the same metadata when requests reach PHP. Ordinary REST metadata aliases remain for inspection but are not treated as substitutes for successful standard discovery. The release does not advertise OpenID Connect: the official MCP SDK requires additional identity-provider fields at its OpenID fallback locations, so no OpenID alias is advertised. If static publication is unavailable and the standard route is blocked, diagnostics report a failure rather than claiming a connection is ready.

The MCP resource and token endpoints are unchanged. Existing opaque grants remain bound to that resource and their current permission checks. Recreate failed connectors with cached 0.2.0 issuer metadata; restart any in-progress sign-in during the upgrade. Proxies and firewalls must still permit public discovery and authenticated REST requests. The issuer is unchanged from 0.2.1. Site-side checks cannot certify external ChatGPT or Claude access.

The administrator can start a 15-minute connection trace and download a JSON report containing fixed stage/error/status classifications and public configuration URLs. It retains at most 50 events, accepts up to four per second, and has a 24-hour retention window with scheduled cleanup. Capture failures do not change OAuth responses. No credentials, payloads, account data or registered client IDs are captured. Static files, browser sign-in/consent and pre-PHP failures are invisible; the trace is deliberately lossy. See [CONNECTION-RECOVERY.md](CONNECTION-RECOVERY.md).

Gateway activation and upgrade hooks create the shared and OAuth tables. Disabling OAuth/the gateway or deactivating the plugin revokes OAuth grants. Actual settings changes revoke unfinished reviewed and direct change records. Cleanup uses WordPress scheduled tasks. Tables and settings are retained on deactivation/deletion; there is no uninstall purge.

The [official WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) supports HTTP and STDIO and translates WordPress abilities into MCP components. JalinWP owns its transport, OAuth layer and typed handlers; it neither bundles that adapter nor exposes arbitrary third-party abilities. Adapter integration can be evaluated separately.

Protocol references: [MCP authorization](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization) and [OpenAI MCP authentication](https://developers.openai.com/plugins/build/auth). These describe the interoperability target; see [VALIDATION.md](VALIDATION.md) for what was actually tested in this release.


## Storage and Interface Changes

For release 0.3.1, the core schema marker remains `0.3.0`; OAuth schema version advances to `3` for `allow_yolo tinyint(1) NOT NULL DEFAULT 0`. Version 0.3.0 introduced the connection timestamps and client-attributed audit fields. Required columns and indexes must be present before markers advance. Existing clients, tokens, issuer and settings are preserved. Legacy unfinished approvals lacking server context are revoked; new proposals are required. Audit client names are escaped display labels copied from the verified grant, not proof that a provider owns a registration name. Historical entries without attribution remain Not Recorded.

Settings forms pair values with a fingerprint from the same database snapshot. An atomic option-row lock, binary conditional write and section-specific merge reject stale same-section changes while preserving unrelated saves. Real changes advance `_policy_revision`; no-op saves do not. Disabled access is persisted before token cleanup; `_oauth_revoke_pending` prevents a failed cleanup from resurrecting old credentials on re-enable. Deactivation uses the same path.

See [PAGE-DESIGN.md](PAGE-DESIGN.md) for design contracts and [VALIDATION.md](VALIDATION.md) for executed and pending gates.
