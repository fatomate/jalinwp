# OAuth Development Update — 0.3.1

> Current navigation (0.3.3): use **Connection Setup** to enable/connect, **OAuth Connections** to manage clients and history, and **Access Controls** to choose permissions and Change Mode. See [0.3.3 Upgrade Notes](UPGRADE-0.3.3.md).

> Branding update: version 0.3.2 uses the JalinWP name and logo. Historical version numbers below describe the existing feature implementation. See [Branding and Upgrade Notes](BRANDING.md) and [Current Validation](VALIDATION.md).

This is the maintained OAuth handoff for 0.3.1. The MCP URL and issuer are unchanged from 0.2.2. Existing grants and opaque credentials survive a healthy schema upgrade. Connection registration, login and token exchange still use the established OAuth flow.

## YOLO Consent and Execution

Access Controls exposes Read Only, Reviewed Changes and YOLO Mode. The site keeps the existing `writes` switch and adds `write_mode`, defaulting to `reviewed`. OAuth schema version `3` adds `allow_yolo tinyint(1) NOT NULL DEFAULT 0` to grants; existing grants remain read-only or reviewed according to their original consent. The core schema marker remains `0.3.0`.

`begin_consent()` reads fresh settings and stores `allow_writes`, `allow_sensitive` and `allow_yolo` in the server-owned request. The form renders that snapshot, including explicit text that YOLO changes run immediately without dashboard approval. `complete_consent()` intersects the snapshot with current settings. Enabling YOLO after a reviewed consent form was rendered cannot widen that pending request. Existing clients reconnect once after site YOLO enablement and approve the new text to receive direct-write permission. Refreshing old tokens does not add it.

`FG_OAuth::authenticate()` returns effective `writes`, `sensitive` and `yolo` flags. `FG_Auth::write_mode()` combines fresh settings with the authenticated request identity, enabled-account membership and consent ceiling. Missing YOLO consent yields `reviewed`; missing write permission yields `read_only`. Native capabilities and per-object checks continue to apply.

`FG_Tools::call()` selects the effective path. YOLO uses `FG_Approvals::execute_direct()`: store `ready` with `execution_mode=yolo`, audit, claim once and execute in the original call. Public `gateway_apply_change` only accepts reviewed records approved in WordPress. Settings changes never apply old requests. Snapshot mode and policy generation must come from one read, with fresh checks before and after the claim; do not use cached mode with a separately fresh revision.

The resulting record and audit trail retain the verified client attribution. Repeating an original write-tool call may duplicate effects, while one change ID cannot execute twice. Mode downgrade stops subsequent direct work, not already executing or committed effects. Design tools keep PHP validation, transactions and recovery; YOLO omits the dashboard's installed-editor JavaScript gate. See [YOLO-MODE.md](YOLO-MODE.md) for behavior, source locations and reproduction commands.

## Retained 0.3.0 Connection Improvements

`FG_OAuth::connections()` now selects original column names and maps display fields in PHP. The prior unquoted `AS sensitive` alias conflicted with MySQL syntax and produced an empty result. A disposable WordPress SQL-parser reproduction established the failure. Native MySQL verification is pending. Listing failures now return `WP_Error` and display an error rather than a false empty state.

The grants table adds nullable consent, token-issuance and first-authentication timestamps. Connection rows distinguish Approval Received, Authorized, Active, Reconnect Required, Revoked and legacy Authorization Recorded. Historical first-authentication times remain unknown; they are not reconstructed from last use. Up to 100 retained grants are listed. This is gateway-observed state, not a live status probe of ChatGPT or Claude.

Audit columns store authentication source, client ID, grant ID and bounded client-name snapshot. `FG_Auth::audit_context()` only exposes verified request identity; it resets after the outer MCP callback while preserving identity through nested internal REST calls. WordPress probes permission callbacks again while generating Allow headers; a request-keyed WeakMap reuses only that completed permission decision, without restoring identity or consuming additional rate slots. Administrator actions pass explicit WordPress Admin attribution. Historical rows are Not Recorded. Never infer a client from User-Agent or caller-supplied tool arguments.

Disabling/re-enabling and deactivation use `FG_Settings` with a pending-revocation marker and fresh conditional writes. Revocation failures cannot silently restore old credentials. Preserve this protocol when changing settings or deactivation hooks. See the maintained OAuth connection/audit, setup and native database tests in the source kit.

---

# OAuth Lifecycle and Connection Internals

These internals retain the connection architecture introduced through 0.2.2 and incorporate the current mode/consent behavior above. Give this guide to the next Codex agent together with the **0.3.1 source ZIP**, [Connection recovery](CONNECTION-RECOVERY.md), [ARCHITECTURE.md](ARCHITECTURE.md), [VALIDATION.md](VALIDATION.md) and the historical 0.1.0 handoff. The older handoff remains a record of 0.1.0; its statements that OAuth is absent, ChatGPT needs an additional gateway, and schema setup runs only at activation are superseded by this release.

This addendum documents implementation and change locations. Exact executed test results belong in `docs/VALIDATION.md`; a source test's existence is not proof that it passed. The user reported working connections after the earlier host firewall fix. Actual ChatGPT/Claude verification of this 0.3.1 update remains a staging acceptance step. GitHub repository creation remains deferred; this release does not imply a push or deployment.

## 1. Problem and development scope

The user tried to connect the 0.1.0 MCP URL directly in ChatGPT and received a sign-in registration error. The old plugin supported WordPress Application Passwords and a Node.js stdio bridge, but no OAuth discovery, registration, login consent or token endpoints. An OAuth-based client could not complete that flow.

The requested change was to make connection setup easier through OAuth. Version 0.2.0 adds a first-party OAuth authorization server inside the WordPress plugin, plus a three-step administration screen. The site issues gateway-scoped tokens after native WordPress login and explicit consent. No password is copied into ChatGPT, and no local Node bridge is required for this connection.

Version 0.2.1 addresses the subsequent GridPane discovery 404. The dedicated issuer ends in `issuer.json`, and a new publisher prepares its public RFC 8414 metadata file inside a confirmed web root. This accommodates GridPane-style static well-known handling without editing server settings. A REST/OpenID fallback was evaluated and rejected after the official MCP SDK required real OpenID provider metadata. This release does not fabricate those capabilities.

Version 0.2.2 addresses two further reproduced compatibility gaps. The callback allowlist excluded hosted Claude, and the single static authorization-server document did not cover clients that proactively discover protected-resource metadata before receiving an MCP 401 challenge. This release accepts Claude's exact documented callback and independently publishes the standard protected-resource document. It expands checks to include that path and the GET challenge, adds a per-request consent CSP, and provides an opt-in bounded trace and public manual-configuration values. The current evidence demonstrates these implementation gaps; it does not prove the exact external request sequence responsible for the user's ChatGPT error.

The existing typed WordPress/WooCommerce tool handlers and financial interpretation remain the product foundation. OAuth adds another authenticated connection identity and a consent ceiling. It does not replace WordPress capabilities or financial metadata configuration. Current effective Change Mode determines whether writes use administrator review or explicitly consented direct execution.

## 2. User flow

1. Upgrade the existing plugin in place to 0.3.1.
2. In **Settings → JalinWP**, click **Enable For My Account**. This enables the gateway and OAuth and allowlists the current administrator, preserving existing finance, data and write settings.
3. Choose data/write access before connecting. Orders, customers, comments and financial reports share the sensitive-data switch. Change Mode offers Read Only, Reviewed Changes or YOLO Mode; existing grants need fresh consent to gain YOLO access.
4. Run **Check Connection**, copy the MCP URL, and create a custom ChatGPT or hosted Claude connector with OAuth and blank client credentials.
5. The client discovers the authorization server and registers automatically. The browser visits WordPress login/consent, then returns to that client after approval.
6. Verify a read. Use propose → WordPress review → apply for Reviewed Changes. With YOLO consent, a write executes in the original tool call without dashboard approval.

Recreate failed connectors created against 0.1.0 or 0.2.0 after checking discovery. The issuer identity changed in 0.2.1; restart sign-in tabs opened before the upgrade. Old connectors may retain their previous metadata/client configuration. Do not ask users to delete the plugin or disable authentication to repair this state.

The issuer and MCP URL remain unchanged between 0.2.1 and 0.2.2. Recreate a failed 0.2.1 connector only if it retains stale metadata/registration state. If a retry still fails, use **Troubleshoot a connection attempt → Start 15-minute connection trace**, retry once, then **Download connection report**. The trace and [Connection recovery](CONNECTION-RECOVERY.md) identify what the plugin can actually observe without requesting credentials or full access logs.

The administrator can revoke individual grants or all grants from the plugin. Enabling new data/write access requires reconnecting for fresh consent. Removing access immediately restricts effective access.

## 3. Files and ownership of behavior

Paths below are relative to the `jalin-mcp-gateway/` plugin directory unless stated otherwise.

| File | Change / responsibility | Where to start editing |
| --- | --- | --- |
| `jalin-mcp-gateway.php` | Version 0.3.1; loads OAuth/discovery/connection/trace modules; boots hooks and registers REST routes; runs upgrade checks. | Include list, boot hooks, `plugins_loaded`, `rest_api_init`. |
| `includes/class-oauth.php` | Entire built-in OAuth provider: discovery, DCR, consent, codes, tokens, storage, expiry and revocation. | `FG_OAuth`; detailed method map below. |
| `includes/class-discovery.php` | Independently publishes authorization-server and protected-resource JSON with verified web-root mapping, ownership/hash checks and a bounded lock. Not hooked on `admin_init`. | `publish()`, `protected_resource_url()`, `webroot()`, `target()`, `resource_target()`, `identifier_target()`, `write()`. |
| `includes/class-auth.php` | Bearer authentication only for the outer MCP endpoint; stable credential ID; scope-of-access intersection; Basic fallback. | `permission()`, `write_mode()`, `allows_tool()`, `challenge()`. |
| `includes/class-server.php` | MCP response transport and challenge headers. | Response-serving hooks and authentication challenge handling. |
| `includes/class-tools.php` | Filters and validates tools using the authenticated grant's consented data/write ceiling. | `write_mode()`, `catalog()`, `permitted()` and validation/call paths. |
| `includes/class-core.php` | `oauth_enabled` default, database version checks, OAuth installation/cleanup/revocation hooks. | `settings()`, `maybe_upgrade()`, `activate()`, `deactivate()`, `cleanup()`. |
| `includes/class-connection.php` | Administrator-only configuration and loopback checks with bounded requests. | `FG_Connection::diagnostics()`. |
| `includes/class-connection-trace.php` | Opt-in REST request summaries, fixed check-status capture, public manual settings and downloadable report projection. | `start()`, `capture()`, `remember_checks()`, `manual_settings()`, `report()`, `cleanup()`. |
| `includes/class-admin.php` | Setup cards, public manual settings, trace/report actions, approved connections and protected form/AJAX actions. | `connect()`, `form()`, `quick_connect()`, `connection_check()`, `connection_check_ajax()`, `start_connection_trace()`, `connection_report()`, `revoke_connection()`. |
| `assets/admin.js` | Copy button and asynchronous check results, with ordinary form submission as a fallback. | Clipboard handler and connection-check submission/rendering. |
| `assets/admin.css` | Connection cards, statuses, diagnostics and responsive controls. | Connection-related selectors appended to the admin design. |
| `tests/oauth.php` | Disposable WordPress OAuth integration and security regressions. | Add a behavioral case for each changed authorization invariant. |
| `../scripts/test-runtime/oauth-tests.php` | Runner wrapper for `tests/oauth.php`. | Disposable fixture integration entry point. |
| `../scripts/test-runtime/admin-connection-tests.php` | Current connection-setup, OAuth-history and admin-guard integration. | Replaces the obsolete 0.2.2 fixture that failed after `FG_Settings` section fingerprints; run with the disposable runner. |
| `../scripts/test-runtime/connection-tests.php` | Diagnostic response/configuration checks using controlled HTTP responses. | Loopback errors, malformed metadata and safe probe configuration. |
| `../scripts/test-runtime/discovery-publisher-tests.php` | File publication, permissions, ownership, path safety and persistence failures in temporary directories. | Publisher filesystem regressions; never point at a user site. |
| `../scripts/test-runtime/discovery-admin-tests.php` | Settings-page GET, protected setup/check POST, and enabled-state AJAX publication fences. | Assert no files/options on render; synthetic HTTP only for diagnostics. |
| `../scripts/test-runtime/connection-trace-tests.php` | Trace retention, privacy projection, rate bounds, concurrent update protection and administrator actions. | Report/capture regression suite. |
| `../scripts/test-runtime/oauth-http-smoke.mjs` | Proactive official SDK discovery before 401, then disposable HTTP login/consent/token lifecycle for either hosted callback. | `--mode` and `--client`; fixture-only HTTPS simulation is not deployed. |
| `docs/CONNECTING-CHATGPT.md` | User setup guide and error recovery. | Keep field labels and steps aligned with the UI. |
| `docs/VALIDATION.md` | Executed checks, environment and remaining staging work. | Update with actual results after testing the final source state. |

The install ZIP must include the new PHP modules and `assets/admin.js`. Test scripts belong in the source kit, not the install ZIP. Preserve the plugin directory name when packaging an upgrade.

## 4. OAuth endpoints and discovery

The canonical resource is `FG_OAuth::resource()`, derived from `rest_url('jalin-mcp/v1/mcp')`. The issuer is `FG_OAuth::issuer()`, derived from `rest_url('jalin-mcp/v1/oauth/issuer.json')` without its trailing slash. Values are site-specific and compared exactly; do not hardcode `firz.my` into PHP.

For a root installation, the endpoints are:

| Purpose | Method and path |
| --- | --- |
| MCP resource | `POST /wp-json/jalin-mcp/v1/mcp` |
| Protected resource metadata advertised by the challenge | `GET /wp-json/jalin-mcp/v1/oauth/protected-resource` |
| Authorization-server metadata REST route | `GET /wp-json/jalin-mcp/v1/oauth/authorization-server` |
| Canonical authorization-server discovery | `GET /.well-known/oauth-authorization-server/wp-json/jalin-mcp/v1/oauth/issuer.json` |
| Protected-resource well-known discovery | `GET /.well-known/oauth-protected-resource/wp-json/jalin-mcp/v1/mcp` |
| Dynamic client registration | `POST /wp-json/jalin-mcp/v1/oauth/register` |
| WordPress login/consent | `GET/POST /wp-admin/admin-post.php?action=fg_oauth_authorize` |
| Code exchange / refresh | `POST /wp-json/jalin-mcp/v1/oauth/token` |
| Token/grant revocation | `POST /wp-json/jalin-mcp/v1/oauth/revoke` |

Use published metadata rather than reconstructing these URLs in a client. `FG_OAuth::well_known()` handles supported discovery paths when the request reaches WordPress. It also supplies GET/OPTIONS responses for discovery. The MCP endpoint remains stateless JSON, not a long-running event stream.

An unauthenticated MCP request receives HTTP 401 and a Bearer `WWW-Authenticate` challenge with `resource_metadata` and scope `mcp` when OAuth is enabled. `FG_Auth::challenge()` selects this challenge; Application Password compatibility remains available for the MCP route.

### Protected discovery publication

`FG_Discovery::publish()` is administrator-only and requires both the gateway and OAuth to be enabled. Successful nonce-protected account setup and the protected Check Connection POST/AJAX actions prepare two documents: RFC 8414 authorization-server metadata and RFC 9728 path-specific protected-resource metadata. The settings page does not publish on `admin_init`, `load-settings_page_*`, or `FG_Admin::page()` GET. Opening or rendering the page does not write files. Upgrades also no longer self-publish; files appear only after Enable for My Account or Check Connection. Each document gets an independent result; a conflict/error at one does not prevent attempting the other. Public OAuth traffic never triggers filesystem writes. Targets derive from the configured HTTPS issuer/resource, require the configured site's origin, and accept safe path segments only. `protected_resource_url()` exposes the standard PR URL for diagnostics and reports.

A root WordPress installation identifies the document root directly. A subdirectory installation requires `DOCUMENT_ROOT` plus the configured WordPress URL path to resolve to `ABSPATH`. Uncertain layouts are skipped rather than guessed. Each directory component is checked; symlink components and occupied non-file paths are rejected. Foreign or externally edited files are left unchanged. The ownership option `fg_oauth_discovery_files` records the confirmed root, relative path and content hash. `fg_oauth_discovery_lock` serializes plugin publication attempts, with bounded stale-lock recovery. Review the implementation for failure handling before changing these guarantees.

Owned replacements and persistence-failure rollback use atomic renames. Initial exclusive creation has a brief partial-read window, and the lock assumes bounded filesystem latency; a concurrent client can retry discovery. Old files are retained rather than deleted on configuration changes.

The files contain only `authorization_metadata()` and `resource_metadata()` output: public issuer/resource identifiers, endpoint URLs and capabilities. They never contain tokens, client secrets, users, shop data or login state. The AS filename ends in `.json`; the RFC 9728 PR path is extensionless. Nginx can therefore serve the latter as `application/octet-stream`. The tested official MCP SDK parses its matching JSON, but stricter clients may reject the MIME type. The diagnostic reports this as a warning, not a verified connection or invalid JSON. PHP `well_known()` continues to return JSON where requests reach WordPress. Metadata remains public after OAuth is disabled or the plugin is removed; grant checks and disabled/missing endpoints prevent authorization. Publication does not delete older files or unrelated `.well-known` content.

The plugin validates the standard AS and PR URLs independently of the challenge-linked REST resource route. AS and REST metadata require matching JSON with the expected JSON content type. Proactive PR metadata always requires matching resource/issuer data; a non-JSON MIME type becomes a compatibility warning. GET and POST MCP probes must both return the expected 401 challenge. The diagnostics perform at most five five-second loopback requests, with redirects disabled and response bodies bounded. No registration is created by the checks. If static and PHP discovery are both blocked, setup reports the obstruction. A global WAF deny, a staging password, or client-rejected MIME handling can still require a host/access fix; automatic publication is not a blanket zero-host-configuration guarantee.

The issuer changed in 0.2.1 and is retained exactly in 0.2.2; it is never selected dynamically from probe results. Protected-resource `authorization_servers`, metadata `issuer` and authorization-response `iss` use the same value. The MCP resource, token endpoints and credential format are unchanged. Existing resource-bound opaque grants and pending write approvals remain subject to their existing checks; this update does not silently widen access or revoke all sessions. Clients caching the pre-0.2.1 issuer and in-flight sign-ins need connector recreation or restart. A fresh connection has its own approval ownership.

Release 0.2.2 used core marker `0.2.0` and OAuth schema `1`. The current 0.3.1 release uses core marker `0.3.0` and OAuth schema `3`; the YOLO update adds the grant consent flag without rotating credentials. Publication and temporary trace continue to use options.

### Why no OpenID fallback is advertised

The MCP specification describes an appended `/.well-known/openid-configuration` fallback. A test with the official MCP TypeScript SDK found that it validates the OpenID provider schema there, including identity-provider fields this OAuth server does not implement. Rather than publish unsupported capabilities, this release serves OAuth authorization-server metadata at its standard RFC 8414 URL. Root-blocked/no-file scenarios must therefore fail discovery; ordinary REST metadata success alone is insufficient.

## 5. Registration and client authentication

`register_client()` accepts a JSON object no larger than 16 KiB. A registration contains one to five exact allowed redirect URLs. The supported callbacks are:

```text
https://chatgpt.com/connector_platform_oauth_redirect
https://chatgpt.com/connector/oauth/{segment}
https://claude.ai/api/mcp/auth_callback
```

The ChatGPT dynamic segment must match the narrow character/length rule in `valid_redirect()`: letters, digits, underscores or hyphens, 1–160 characters. Claude's entry is the exact documented callback for hosted web, Desktop, mobile and Cowork. This release does not add guessed `claude.com` alternatives or Claude Code loopback callbacks. Subdomains, arbitrary paths, query strings, fragments, HTTP callbacks and other domains are not accepted. Authorization later requires the exact URI registered by that client. There is no general callback editor.

Supported token authentication methods are `none`, `client_secret_basic` and `client_secret_post`; the default is `client_secret_basic`. Confidential clients receive a generated secret once at registration. Only its SHA-256 hash is stored. Public clients use PKCE without a client secret. All clients use authorization code plus S256; a client secret does not exempt a client from PKCE.

Established client registrations do not automatically expire: their stored `expires` value is `0`, and confidential registration responses set `client_secret_expires_at: 0`. First successful consent marks the registration `authorized=1`, preserving its identity so the connector can reconnect after its 30-day grant expires. A registration alone has no user-data access.

Never-consented registrations are pruned after one day by scheduled cleanup. When the 500-client cap is reached, bounded cleanup can reclaim abandoned registrations older than one hour, excluding clients with an active consent request. A client that abandons initial setup long enough for registration cleanup may need its connector recreated. There is no client registration-management API or manual client-credential creation screen; 500 established registrations still require deliberate administrative capacity management.

The token/revocation Basic header contains an OAuth client ID and secret, not WordPress credentials. `FG_OAuth::boot()` filters `application_password_is_api_request` only for these client endpoints so WordPress does not attempt to interpret that header as an Application Password. Do not broaden that filter to all REST requests.

## 6. Login, consent, codes and effective permissions

`authorization_request()` validates client identity, exact redirect, response type `code`, exact resource, scope `mcp`, S256 challenge and bounded state. Unknown clients/callbacks are rejected before redirecting to an untrusted destination. The `scope` field defaults to `mcp` if omitted on authorization, while the resource and S256 challenge are mandatory.

`authorize_page()` uses native WordPress login. After login, `begin_consent()` creates a short-lived request bound to the WordPress user and login session. The page shows the site, client name, account, data access and whether Read Only, Reviewed Changes or YOLO access is being requested. The client name is registration-supplied display text, not an independently verified publisher identity. The form posts a request ID and a WordPress nonce; it does not trust browser-posted replacements for the original client, callback, resource or PKCE parameters.

`consent_policy()` starts with `form-action 'self'` and adds only the origin derived from a callback accepted by `valid_redirect()`. The validated GET consent page and successful/denied POST redirect receive that request-specific policy; responses before callback validation remain self-only. The POST policy derives from the callback returned by validated stored consent, not browser-posted OAuth fields. The policy retains `default-src 'none'`, restricted inline styling, no framing and no base URI. Supporting a new callback requires reviewing this browser navigation boundary as well as DCR. The tests assert both generated policy restrictions and actual HTTP consent/redirect headers.

`complete_consent()` validates ownership, login session, expiry and one-use state, then either returns `access_denied` or issues a short-lived code. The response preserves state and includes the issuer. `begin_consent()` stores immutable `allow_writes`/`allow_sensitive`/`allow_yolo` flags in the request; the screen renders those flags, and completion grants their intersection with current settings. Later settings changes cannot widen an existing grant or a pending consent screen.

Authorization requests and codes expire after five minutes. Code exchange requires the same client, exact redirect, exact resource and matching 43–128-character verifier. Codes are atomically consumed. A validated replay revokes the grant instead of minting another token pair.

Effective tool access is:

```text
consented data/write permission
  AND current gateway data/write settings
  AND current allowed-user membership
  AND current WordPress capability / native object permissions
```

`FG_OAuth::authenticate()` returns a WordPress user ID, a stable grant UUID as `credential_id`, and effective `writes`/`sensitive`/`yolo` flags. `FG_Auth::permission()` sets this identity only for `/jalin-mcp/v1/mcp`. `FG_Auth::allows_tool()` and `FG_Tools::permitted()` apply the grant ceiling. Cookie-only MCP access and forged bearer credentials remain rejected. A bearer token does not authenticate `/wp/v2/*` or `/wc/v3/*` directly.

## 7. Token and grant lifecycle

| Item | Lifetime / behavior |
| --- | --- |
| Consent request | Five minutes, native login-session bound, one use. |
| Authorization code | Five minutes, PKCE/client/redirect/resource bound, one use. |
| Access token | At most one hour, also bounded by the grant's remaining lifetime. |
| Refresh token | Valid until the grant expires; one-use rotation. |
| Grant | Fixed 30-day lifetime from consent. Refresh does not extend it. |
| Established client registration | Does not expire automatically; no data access without a current user grant. |
| Never-consented registration | Eligible for cleanup after one day, or after one hour under capacity pressure. |

Secrets and tokens are 32 random bytes encoded with base64url; only hashes are stored in the corresponding secret/code/token database fields. Access tokens are opaque, not JWTs. The server performs a database/grant check for each use. Keep raw credentials out of audit records, fixtures committed to source, screenshots and support messages.

Refresh atomically marks the old refresh token used and issues a fresh access/refresh pair. Reuse of a previously consumed refresh token revokes that grant's token family. Clients should serialize refreshes and replace the stored refresh token after success. A lost token response or duplicate refresh can require reconnecting; do not retry uncertain writes as part of authentication recovery. Previously issued access tokens can remain usable until expiry while the grant stays active; grant revocation blocks them together.

`active_grant()` rechecks allowed membership, current client validity, exact resource and a user fingerprint derived from the WordPress password hash, auth salt and current revocation epoch. Password changes, auth-salt changes, deleted/excluded users, missing registrations, or a changed resource URL invalidate access. Some conditions invalidate the check without immediately rewriting the database row's displayed status. Disabling gateway/OAuth or explicitly revoking marks grants revoked.

Revocation changes grant status, so all associated tokens stop authorizing. It does not need to enumerate and delete every token immediately. The client revocation endpoint authenticates the registered client and only revokes grants owning that client's token. Unknown tokens do not reveal another connection's state. Administrator revocation actions require native administrator permissions, POST and a nonce.

`revoke_all()` also rotates `fg_oauth_epoch` before marking current grants and pending consent requests revoked. Pending requests store their starting epoch; grant fingerprints bind to that epoch. This prevents an in-flight consent from recreating usable access from an older authorization after disconnect-all. Epoch reads bypass the per-request WordPress option cache so current revocation is observed. Revocation returns success/failure to the admin actions; the UI must not show success when persistence fails.

### Write approvals across refresh and reconnect

The existing `fg_changes.credential_id` field holds the OAuth grant UUID, preserving ownership when access tokens rotate. The same user with a different grant cannot apply another connection's change. Reconnecting creates a fresh grant. Actual settings changes revoke pending/reviewing/approved/ready changes, even when the OAuth connection itself remains active. No-op saves preserve them. A YOLO-consented connection can inspect its own direct record but cannot execute it again through `gateway_apply_change`.

The approval system still provides a single-use execution claim per change ID, not a transaction across every WordPress/WooCommerce hook. Authentication upgrades do not add payment capture, refunds, rollback or automatic retries.

## 8. Storage, upgrades and operational limits

The existing `fg_changes`, `fg_audit` and `fg_limits` tables remain. OAuth adds five tables, each with the site's WordPress table prefix:

| Table suffix | Stored purpose |
| --- | --- |
| `fg_oauth_clients` | UUID, hashed client secret, name, authentication method, exact redirect list, ever-authorized flag and expiry field (`0` for non-expiring registrations). |
| `fg_oauth_requests` | Consent request UUID, user, hashed login-session token, validated parameters with access snapshot/epoch, status and expiry. |
| `fg_oauth_grants` | Stable UUID, client/user, user fingerprint, exact resource, consented flags, status, creation/expiry and last use. |
| `fg_oauth_codes` | Code hash, grant/client, redirect, S256 challenge, resource, consumed flag and expiry. |
| `fg_oauth_tokens` | Token hash, grant, access/refresh kind, status, creation and expiry. |

`FG_Core::maybe_upgrade()` runs on `plugins_loaded`. Core schema state is tracked with `fg_db_version`; OAuth has its own `fg_oauth_schema` marker and `FG_OAuth::VERSION`, and installation creates `fg_oauth_epoch`. Installation verifies required columns and ordered indexes before recording schema success, allowing failed creation or migration to retry. If changing schemas later, update the relevant marker and migration logic rather than relying on reactivation alone. Required-column/index checks are not a comprehensive schema-drift audit.

The settings default `oauth_enabled` to false. The explicit quick-setup action enables it. Saving access settings preserves the selected configuration; disabling OAuth or the whole gateway revokes all OAuth grants. Deactivation disables the gateway, revokes grants and unexecuted changes, and stops scheduled cleanup. Data is retained; there is no uninstall purge.

`FG_Core::cleanup()` calls OAuth cleanup on the daily WordPress schedule. Expired OAuth rows with a positive expiry are deleted. Established client registrations remain; scheduled cleanup checks up to 100 never-consented registrations older than one day, and registration capacity pressure checks up to 10 older than one hour. Active consent requests are preserved. Revoked grants and used refresh-token hashes remain until their original expiry, allowing replay checks while that family is relevant. If WordPress cron does not run, storage accumulates and limits can be reached; configure reliable scheduled execution on the host.

Current limits are deliberately bounded and should be reviewed with the actual deployment volume:

| Control | Current bound |
| --- | --- |
| Registration requests | 20/hour per `REMOTE_ADDR`, 100/hour globally. |
| Token requests | 120/minute per `REMOTE_ADDR`, 600/minute globally. |
| Consent requests | 30/minute per `REMOTE_ADDR`. |
| Revocation requests | 60/minute per `REMOTE_ADDR`. |
| Registered clients / consent requests / grants | 500 rows each. |
| Authorization codes | 1,000 rows. |
| Tokens | 50,000 rows. |
| Listed connections | Latest 100 retained grants, including revoked/expired records and observed lifecycle states. |
| Ordinary MCP requests | Existing 60/minute per WordPress user. |

Limits use direct `REMOTE_ADDR`, not untrusted forwarded headers. A proxy can cause many clients to share an IP bucket. Row-cap checks use count-then-insert logic, so simultaneous requests can temporarily exceed the stated cap; these are capacity guards, not strict concurrent quotas. They do not replace database sizing and deployment monitoring. For any changes, update code, diagnostics text, tests and this table together.

### Temporary connection trace and manual configuration

Tracing is off by default. **Start 15-minute connection trace** requires an administrator, POST and the action nonce. `FG_Connection_Trace::start()` replaces the prior capture and initializes fixed option records. `capture()` observes the six fixed gateway REST routes at `rest_post_dispatch`: MCP, registration, token, revocation, authorization metadata and resource metadata. It records at most four events per second and retains the latest 50 summaries. A fixed rate row and compare-and-swap update limit writes without overwriting a newer trace; concurrent or rapid requests may be dropped. Trace failures do not change the OAuth response.

Capture expires after 900 seconds. Retention is 24 hours, with a scheduled cleanup event and cleanup on report access; physical scheduled deletion depends on WordPress cron running. Options are `fg_connection_trace`, `fg_connection_trace_slot` and `fg_connection_trace_checks`. Last-check storage projects only fixed labels/statuses, never upstream detail text or account counts. The report re-projects all summaries, dropping additional fields even if another option writer inserted them. Events contain only timestamp, fixed stage, method, HTTP status, fixed error category, authentication scheme and callback family. Callback family describes submitted URLs and does not authenticate the caller's identity.

**Download connection report** is also a protected administrator POST. The JSON includes plugin version, public site/MCP/issuer/endpoint URLs, last fixed check statuses, bounded summaries and the capture limits. It excludes tokens, credentials, codes, state, cookies, client IDs, request/response bodies, IP addresses, user agents, account identities and shop data. Nothing is transmitted automatically. The **Advanced OAuth settings** panel uses `manual_settings()` to show public Issuer, Authorization URL, Token URL, Registration URL and Scope; it does not generate manual client credentials or bypass unreachable endpoints.

This trace can observe only REST requests that reach WordPress. It cannot see Nginx-served static discovery files, browser login/consent navigation, external client caching or requests blocked before PHP. Host loopback checks can appear. An empty report does not prove no client request occurred, and missing stages do not alone identify a firewall. Use the bounded evidence and browser error together, following [Connection recovery](CONNECTION-RECOVERY.md).

## 9. Tests and reproduction

The source kit has a `scripts/test-runtime/` folder. Use its disposable fixture runner, never a live or staging site's database for destructive integration tests. Follow [Developer Tests](DEVELOPER-TESTS.md) for prerequisites and fixture creation.

From the source-kit root, the core commands are:

```bash
cd scripts/test-runtime
npm ci
node setup-fixture.mjs
node run-wp-tests.mjs oauth-tests.php
node run-wp-tests.mjs setup-030-tests.php
node run-wp-tests.mjs setup-yolo-tests.php
node run-wp-tests.mjs yolo-oauth-tests.php
node run-wp-tests.mjs yolo-execution-tests.php
node run-wp-tests.mjs yolo-metadata-tests.php
node run-wp-tests.mjs connection-tests.php
node run-wp-tests.mjs discovery-publisher-tests.php
node run-wp-tests.mjs discovery-admin-tests.php
node run-wp-tests.mjs admin-connection-tests.php
node run-wp-tests.mjs connection-trace-tests.php
node oauth-http-smoke.mjs --mode=normal --client=chatgpt
node oauth-http-smoke.mjs --mode=gridpane-static --client=chatgpt
node oauth-http-smoke.mjs --mode=gridpane-static --client=claude
node oauth-http-smoke.mjs --mode=blocked-root --client=chatgpt
node run-wp-tests.mjs security-tests.php
node run-wp-tests.mjs wp-tools-tests.php
node run-wp-tests.mjs wc-tools-tests.php
node run-wp-tests.mjs analytics-tests.php
node run-wp-tests.mjs analytics-hpos-tests.php
node --test ../scripts/bridge/test.mjs
```

`setup-fixture.mjs` refuses to replace an already populated fixture; do not rerun it as a reset command. Run suites sequentially where they share output filenames. `npm test` alone is not the full regression suite. Use the source kit's PHP WASM CLI or a matching native PHP binary to lint changed PHP files, and `node --check` for changed JavaScript.

The disposable runner's directory removal uses bounded retries for a late filesystem flush that can otherwise produce `ENOTEMPTY` during cleanup. This changes cleanup resilience, not test assertions or the suite's failure criteria.

The OAuth suite exercises actual WordPress REST handlers and a disposable database. Its cases cover discovery, registration/client authentication, callback restrictions, native session/nonce consent, PKCE and audience checks, code/refresh replay, expiry, revocation, tool access, connection-bound write ownership and credential storage. It also includes regressions for settings changes during consent and after consent. Consult the actual case names and final validation report; do not convert this list into a claim that every external browser/proxy path was exercised.

The 0.2.2 additions test Claude's exact callback against lookalikes, modified paths, query strings and fragments; preserve its callback through WordPress login and nonce consent; exchange codes and exercise catalog access/refresh/replay; and check restrictive per-client CSP. Existing ChatGPT cases continue to pass. Publisher tests cover two independent documents and conflict behavior. Trace tests cover its privacy projection, rate/retention bounds, concurrent updates, protected admin actions and failure isolation.

The admin connection suite checks the setup/preservation actions, protected diagnostics/revocation entry points and rendered UI. Its OAuth/diagnostics collaborators are stubbed where needed to isolate admin behavior. The diagnostic suite controls outbound HTTP responses to test malformed metadata and transport failures. Passing either suite is not equivalent to a full ChatGPT sign-in.

The HTTP smoke script uses pinned official MCP SDK 1.30.0 `discoverOAuthServerInfo(MCP URL)` before any MCP challenge, without a known issuer or `resourceMetadataUrl` override. The SDK must find the path-specific PR document, then the exact issuer's RFC 8414 AS document. It also verifies challenge-led discovery. Normal mode reaches WordPress. GridPane-static mode intercepts well-known paths and serves exactly the two plugin-owned files, with the extensionless PR file deliberately using `application/octet-stream`. Blocked-root mode verifies that both discovery paths fail even while REST aliases remain reachable. These are simulations, not tests on a live GridPane server.

Result filenames contain mode and callback family, such as `oauth-http-gridpane-static-claude-output.json`, so runs do not overwrite one another. `--client=chatgpt` is the default; `--client=claude` selects the documented hosted callback. The official SDK exercises discovery; a synthetic local HTTP client performs the remaining OAuth lifecycle. Neither is the actual ChatGPT or Claude application.

The HTTP smoke script launches a disposable local WordPress HTTP server, establishes a synthetic native WordPress login, parses and submits the rendered consent form, checks its CSP and approval response CSP, exchanges the code, and calls the MCP endpoint and refresh flow. It uses a fixture-only must-use plugin to simulate HTTPS detection over local HTTP and never follows the external ChatGPT/Claude callback or sends its code there. That adaptation is never installed on the user's site. Cookie handling is explicit HTTP logic, not browser automation or a visual/browser compatibility test. These checks do not establish public TLS, hosting rules or actual application compatibility.

Historical 0.2.2 results (not evidence for the 0.3.1 source state):

| Suite | Result |
| --- | --- |
| OAuth | 41/41 |
| Discovery publisher | 41/41 |
| Connection diagnostics | 53/53 |
| Temporary trace/report | 50/50 |
| Setup/admin | 33/33 |
| HTTP GridPane simulation, ChatGPT callback | 29/29 |
| HTTP GridPane simulation, Claude callback | 29/29 |
| HTTP normal routing, ChatGPT callback | 27/27 |
| HTTP blocked root, expected discovery failure | 7/7 |

The last row verifies correct failure reporting, not a successful connection. `VALIDATION.md` and packaged evidence remain the authoritative release record. Commands listed above for legacy tools, bridge and finance are reproduction instructions; their presence does not mean those suites were rerun for this release.

Existing financial/HPOS fixtures do not become native MySQL coverage merely because OAuth was added. Historical 0.1.0 finance results remain historical unless explicitly rerun and recorded; keep the test-only SQLite/HPOS shim caveat. The user's actual login extensions, proxy headers, caches, firewall, TLS and ChatGPT/Claude behavior require public staging acceptance.

## 10. How to make changes safely

| Desired change | Implementation locations | Verification needed |
| --- | --- | --- |
| Make setup wording clearer | `class-admin.php`, `CONNECTING-CHATGPT.md`, README. | Render at desktop/mobile widths; confirm labels and saved values still match. |
| Add a connection check | `class-connection.php`; UI renders returned checks. | Bound URL/timeout/response; verify failures do not expose upstream secrets; test a genuine failure and success. |
| Support another OAuth client | `valid_redirect()`, `consent_policy()`, metadata/registration policy, user-facing setup docs. | Exact callback matching, client auth, PKCE, consent CSP and full external round trip; do not add wildcard callbacks. |
| Change discovery publication | `class-discovery.php`, `class-connection.php`, HTTP/publisher fixtures. | Independent AS/PR outcomes, foreign-file preservation, MIME warnings, proactive SDK discovery without a challenge. |
| Add trace information | `class-connection-trace.php`, admin UI/report, trace tests. | Fixed fields only; retain opt-in, rate/retention bounds, no credentials or bodies, projection on capture and export, failure isolation. |
| Add CIMD | A separately designed client-identity resolver, metadata and tests. | SSRF protections, URL identity validation, document refresh, redirect policy and compatibility. A metadata flag alone does not implement CIMD. |
| Change token lifetime | `ACCESS_TTL`, `GRANT_TTL`; consent/admin/docs text. | Boundary expiry, fixed grant cap, refresh, old-client behavior and reconnection. |
| Add a permission category | Grant/request schema and consent text; `FG_Auth`, `FG_Tools`, settings/admin. | Existing grants never widen; pending consent never widens; native capabilities and direct calls still enforced. |
| Add or modify a tool | Appropriate typed handler module and `FG_Tools` registration. | Mutating/sensitive flags, schema rejection, native permission checks and proposal/apply ownership. |
| Change DB schema | Core/OAuth installation and their version markers. | Upgrade from 0.1.0 and 0.2.0, failed DDL retry, existing-settings preservation; test on native MySQL too. |
| Change revocation | `revoke()`, `revoke_all()`, `active_grant()`, token endpoint and admin actions. | All token generations and proposal ownership, current policy, expiry and endpoint authentication. |
| Investigate fee discrepancies | `class-analytics.php`, mappings and `ANALYTICS.md`. | Known paid orders, units/currencies/refunds/coverage; OAuth itself does not reconcile fees. |

Do not remove Origin validation to repair OAuth. Origin allowlists are separate from redirect URIs, and server-to-server ChatGPT calls ordinarily need no additional browser origin. Do not make bearer authentication global to WordPress REST, accept token-bearing query strings, log secrets, replace PKCE with a shared secret, or silently widen consent to repair a connection. YOLO is an explicit Change Mode choice with fresh per-connection consent, not a connectivity workaround.

Before release, build the install/source ZIPs from the same final code, update version references and validation notes, verify the install includes every new runtime file, and keep old 0.1.0 artifacts intact as historical builds. Do not copy `node_modules`, disposable WordPress sites, runtime databases, generated credentials or raw test output into the install ZIP.

## 11. Known limits and next acceptance step

- This is a site-hosted OAuth provider with narrow ChatGPT and hosted Claude callback rules, not a hosted multi-site service or general identity provider.
- It implements authorization code/refresh, not OpenID Connect, implicit/password/client-credentials grants, CIMD, or arbitrary client registration-management endpoints.
- Metadata advertises interoperability targets; actual client and hosting compatibility require an external connection test.
- Automatic metadata publication requires a confirmed writable web root. Both standard documents must be publicly reachable; custom policies blocking static and PHP discovery can still require host intervention. Extensionless PR MIME handling can produce a warning and remains client-dependent.
- Loopback diagnostics cannot prove external ChatGPT/Claude access. The temporary trace cannot observe static documents, browser login/consent or blocks before PHP, and deliberately omits some rapid/concurrent requests.
- Consent is a connection-level ceiling. Reviewed Changes requires WordPress approval per change. YOLO requires explicit direct-write consent and bypasses that dashboard step while retaining other checks.
- Grant expiry and refresh reuse can require reconnecting. Clients must handle that explicitly. Non-expiring client registrations have a storage cap and no general management UI yet.
- Native database, extensions and production-load behavior remain subject to staging validation.

The next agent should first read `VALIDATION.md` and [Connection recovery](CONNECTION-RECOVERY.md), install the supplied 0.3.1 ZIP on the user's public HTTPS staging site, run diagnostics, and complete a new ChatGPT or hosted Claude OAuth connector with blank client credentials. If connection fails, capture one attempt with the temporary trace and interpret its limits. Confirm one read, denied access, revocation and one reviewed harmless write. Then explicitly enable YOLO, reconnect, verify one direct draft write and test a mode downgrade. Actual native MySQL/MariaDB and live-client verification remain required; local fixtures do not establish those results.

## References

- [GridPane well-known routing](https://gridpane.com/kb/ssl-renewals-for-domains-that-redirect-to-an-internal-page/): documented unanchored well-known location; actual installed configurations can differ.

- [OpenAI MCP authentication](https://developers.openai.com/plugins/build/auth): client-facing OAuth discovery and connection requirements.
- [Claude connector authentication](https://claude.com/docs/connectors/building/authentication): exact hosted callback, client authentication, PKCE and token requests.
- [MCP authorization specification, 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization): resource discovery, OAuth and resource binding.
- [RFC 8414](https://www.rfc-editor.org/rfc/rfc8414): authorization-server metadata and path-aware well-known discovery.
- [RFC 7636](https://www.rfc-editor.org/rfc/rfc7636): PKCE.
- [RFC 7591](https://www.rfc-editor.org/rfc/rfc7591): Dynamic Client Registration.
- [RFC 8707](https://www.rfc-editor.org/rfc/rfc8707): OAuth resource indicators.
- [WordPress REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/): the separate Application Password path.

These are design references. The packaged source is authoritative for implemented behavior; the validation report is authoritative for executed checks.
