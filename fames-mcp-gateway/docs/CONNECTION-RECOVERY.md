# Historical Fames MCP Gateway 0.2.2 Connection Recovery

This historical report describes the 0.2.2 connection repair. For current 0.3.1 installation and Change Mode choices, use [Connecting ChatGPT or Claude](CONNECTING-CHATGPT.md) and [YOLO Mode](YOLO-MODE.md). The old version numbers and original test evidence below are retained as investigation history.

That update addresses the screenshots where WordPress checks passed, ChatGPT reported that the MCP server did not implement OAuth, and Claude could not register. It fixes two reproducible compatibility gaps. It does not claim that the actual requests from the user's ChatGPT or Claude account have been observed or that the live connection has succeeded.

## Update and connect

1. Upload `fames-mcp-gateway-0.2.2.zip` through WordPress and replace the existing plugin.
2. Open **Settings → Fames MCP Gateway** and confirm version **0.2.2**. Keep the gateway/OAuth enabled and your account allowed.
3. Run **Check connection**. This prepares both public discovery files and checks the standard resource-discovery path as well as the existing challenge path.
4. Use the same MCP URL displayed by the plugin. For the reported site it is `https://firz.my/wp-json/fames-mcp/v1/mcp`.
5. Recreate the failed ChatGPT or Claude connection with OAuth and automatic client registration. Leave Client ID and Client Secret blank where those optional fields are shown. Sign in to WordPress and approve access.

The issuer, MCP URL and existing resource-bound grants from 0.2.1 are unchanged. In that 0.2.2 update, existing settings, finance mappings and write-approval requirements were preserved. The older 0.2.0 issuer change still requires recreating a connector that retains that old metadata.

## If the connection still fails

On the plugin settings page:

1. Run **Check connection** first.
2. Click **Start 15-minute connection trace**.
3. Immediately make one connection attempt in the failing app.
4. Click **Download connection report** and provide that JSON file to the next debugging agent.

A trace is optional, ends after 15 minutes and records a bounded set of request stages and error codes. It excludes credentials, tokens, authorization codes, state, cookies, request/response bodies, IP addresses, user agents, account identities, client IDs and shop data. The report includes public site/configuration URLs. The capture is rate-limited and can omit requests; it is a troubleshooting aid, not a complete access log.

Only gateway REST requests reaching WordPress can appear. Public files served directly by Nginx, the browser's WordPress sign-in/consent navigation, and requests blocked before PHP are not captured. The host's own connection checks can appear. An empty trace does not prove the app sent no requests: cached configuration, client-side failures and blocks before PHP are also possible. No automatic transmission of the report occurs.

Interpret the sequence rather than one generic connection message:

| Report observation | Meaning / next investigation |
| --- | --- |
| Standard resource discovery fails while direct REST metadata passes | The client that discovers before receiving a challenge cannot use that entry point. Inspect the specific publication or HTTP result. |
| A registration request returns `invalid_redirect_uri` | The submitted callback was outside the exact supported policy. The report identifies only the callback family, not the full callback string. |
| A registration request returns `temporarily_unavailable` | Check whether the service is enabled, rate limits or storage prevented registration. The code alone does not identify which condition. |
| Registration succeeds but no token request appears | Check the browser's sign-in/consent result and client callback. Trace sampling or pre-PHP blocking can also omit later requests. |
| Token request returns `invalid_client` | Client identity or its registered authentication method did not match. Recreate the failed registration; do not substitute WordPress credentials. |
| Token request returns `invalid_target` or `invalid_grant` | Inspect resource consistency, PKCE, expiry or code/refresh reuse with the next agent. Never share raw tokens/codes to debug it. |
| MCP request returns `fg_origin` | A browser-origin request reached a policy that does not allow it. Verify the actual client transport before changing allowed origins. |
| No gateway request appears | Inspect client/network/host access logs around the report's UTC timestamp. The plugin cannot identify a block that happened before it ran. |

## Manual settings shown in ChatGPT

The screenshot's **Advanced OAuth settings** offers manual configuration. The plugin now displays an **Advanced OAuth settings** section with the authoritative public values. If automatic discovery still fails and the app offers manual endpoint entry, copy those values and choose automatic/dynamic client registration if offered.

For `firz.my`, the current public values are:

| Field | Value |
| --- | --- |
| MCP server URL | `https://firz.my/wp-json/fames-mcp/v1/mcp` |
| Issuer | `https://firz.my/wp-json/fames-mcp/v1/oauth/issuer.json` |
| Authorization endpoint | `https://firz.my/wp-admin/admin-post.php?action=fg_oauth_authorize` |
| Token endpoint | `https://firz.my/wp-json/fames-mcp/v1/oauth/token` |
| Registration endpoint | `https://firz.my/wp-json/fames-mcp/v1/oauth/register` |
| Scope | `mcp` |

These are endpoint/configuration values, not client credentials. Do not put a WordPress username, login password or Application Password into Client ID/Client Secret. Manual endpoint entry cannot resolve a firewall that blocks the actual OAuth requests. The exact client UI can differ; use the fields it actually provides.

## What changed and why

### 1. Claude registration and browser consent

The older plugin accepted only ChatGPT callback URLs. Claude's hosted callback `https://claude.ai/api/mcp/auth_callback` was therefore rejected during Dynamic Client Registration. Version 0.2.2 allows that exact documented HTTPS callback. Lookalike domains, arbitrary subdomains, appended query strings and guessed alternative callbacks remain rejected.

Consent text is client-neutral. Its Content-Security-Policy permits the validated requesting client's origin, and starts with self-only form submission until the callback is validated. WordPress login, explicit consent, nonce/session binding, PKCE S256 and exact resource checks remain enforced. At version 0.2.2, all writes also required review; 0.3.1 adds explicit YOLO consent as described in the current guides.

### 2. Discovery before a challenge

Version 0.2.1 published the authorization-server document and tested protected-resource metadata through its direct REST URL. The installed official MCP SDK also supports proactive discovery from the MCP URL before receiving the server's `WWW-Authenticate` header. With GridPane-style static handling, the missing standard protected-resource file made that sequence fail even while the old plugin checks passed.

Version 0.2.2 independently publishes both files:

| Public document | Path on the reported site |
| --- | --- |
| OAuth authorization server | `/.well-known/oauth-authorization-server/wp-json/fames-mcp/v1/oauth/issuer.json` |
| MCP protected resource | `/.well-known/oauth-protected-resource/wp-json/fames-mcp/v1/mcp` |

Each publication retains path, ownership, symlink, conflict, lock and persistence protections. Failure of one file does not prevent preparing the other. Existing certificate challenge files and other services' discovery documents are not overwritten. The plugin does not edit Nginx configuration.

The protected-resource path is extensionless because the existing MCP endpoint is unchanged. Its static Content-Type is controlled by the host. The official SDK parsed valid JSON served as `application/octet-stream` in the test, but RFC-compliant metadata should be served as `application/json`, and strict clients may reject another type. Diagnostics show this as a warning rather than falsely claiming MIME compliance. If a strict client rejects it, a host MIME rule or a different deployment route can still be necessary. This release cannot promise zero host changes under every configuration.

### 3. Checks and temporary trace

Diagnostics now independently check authorization metadata, direct protected-resource metadata, proactive protected-resource metadata, MCP POST challenge and MCP GET challenge. Requests are bounded to five requests at five seconds each. These are host-side loopback checks, not proof of external client reachability.

The temporary trace observes fixed gateway REST routes after response dispatch. It stores only fixed classifications, status and error codes and re-projects the report to exclude additional fields. Admin actions require POST, administrator capability and action nonces. Ordinary OAuth behavior continues if tracing fails. Capture stays off until explicitly started.

## Development map

| Change | File / method |
| --- | --- |
| Exact supported callbacks and consent policy | `includes/class-oauth.php`: `valid_redirect()`, `consent_policy()`, `authorize_page()` |
| Two public documents and per-file results | `includes/class-discovery.php`: `publish()`, `protected_resource_url()`, target/path and ownership helpers |
| Proactive discovery and GET/POST checks | `includes/class-connection.php`: `diagnostics()` |
| Bounded trace/report and public manual settings | `includes/class-connection-trace.php`: `boot()`, `start()`, `capture()`, `report()`, `cleanup()` |
| Trace actions, downloads and manual settings UI | `includes/class-admin.php` |
| Module loading and release version | `fames-mcp-gateway.php` |
| OAuth callback/consent regression cases | `tests/oauth.php` |
| Publisher safety tests | `test-runtime/discovery-publisher-tests.php` |
| Controlled discovery tests | `test-runtime/connection-tests.php` |
| Trace/admin/report safety tests | `test-runtime/connection-trace-tests.php` |
| Official SDK proactive discovery and full HTTP flow | `test-runtime/oauth-http-smoke.mjs` |

The source ZIP includes the complete plugin, pinned runtime dependencies, test scripts and current evidence. Follow `test-runtime/DEVELOPER-TESTS.md` to reproduce on disposable fixtures. Do not run destructive fixtures against a real shop. See `docs/VALIDATION.md` for the executed results and `docs/OAUTH-DEVELOPMENT.md` for the full OAuth lifecycle and finance/tool change locations.

## Verification boundary

The tests use actual WordPress/PHP and synthetic accounts. The HTTP harness uses official MCP SDK 1.30.0 for proactive discovery, then a local HTTP client for native login/consent, code exchange, reads and refresh checks. GridPane routing is simulated with a reverse proxy serving the public metadata before PHP. Neither ChatGPT nor Claude receives the fixture's authorization codes.

A successful live ChatGPT/Claude connection to `firz.my` is still unverified. The reproduced discovery sequence demonstrates a real compatibility gap; it does not establish the exact request sequence that produced the user's ChatGPT screenshot. No live hosting configuration, credentials, orders or GitHub repository were changed.

## Primary references

- [Claude connector authentication](https://claude.com/docs/connectors/building/authentication): exact hosted callback and OAuth requirements.
- [OpenAI MCP authentication](https://developers.openai.com/plugins/build/auth): protected-resource discovery and challenges.
- [MCP authorization specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization): discovery mechanisms and resource binding.
- [GridPane well-known routing](https://gridpane.com/kb/ssl-renewals-for-domains-that-redirect-to-an-internal-page/): documented static-route behavior; installed configurations can differ.
