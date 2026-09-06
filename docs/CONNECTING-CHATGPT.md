# Connect ChatGPT or Claude to JalinWP 0.3.1

> Current navigation (0.3.3): use **Connection Setup** to enable/connect, **OAuth Connections** to manage clients and history, and **Access Controls** to choose permissions and Change Mode. See [0.3.3 Upgrade Notes](UPGRADE-0.3.3.md).

> Branding update: version 0.3.2 uses the JalinWP name and logo. Historical version numbers below describe the existing feature implementation. See [Branding and Upgrade Notes](BRANDING.md) and [Current Validation](VALIDATION.md).

Version 0.3.1 adds opt-in YOLO Mode for writes without WordPress dashboard approval. Access Controls now offers Read Only, Reviewed Changes and YOLO Mode. It retains the one-click enable/disable, account setup, repaired connection list and client attribution from 0.3.0, together with the hosted OAuth callbacks, public discovery documents and optional connection trace introduced earlier. The plugin prepares public metadata after an explicit **Enable For My Account** or **Check Connection** action, provided its confirmed web root is writable; viewing settings pages does not publish files. No Nginx configuration is edited. You do not need a WordPress Application Password, Node.js or the local bridge for this flow.

## Historical Connection and Issuer Changes

The first GridPane screenshot showed OAuth discovery returning HTTP 404 while the WordPress REST metadata and MCP challenge worked. Version 0.2.1 prepared the authorization-server metadata file. Later screenshots showed those checks passing while ChatGPT still failed discovery and Claude failed registration. Two gaps were then reproduced in the implementation: proactive discovery also needs the standard protected-resource document, and the earlier callback policy rejected Claude. Version 0.2.2 fixed both implementation gaps. Subsequent host logs confirmed a separate GridPane 7G bad-bot block, and the user reported that connections worked after changing that host rule. These are distinct stages of the earlier investigation. See [Connection recovery](CONNECTION-RECOVERY.md) for the evidence and recovery steps.

**The issuer changed in 0.2.1 and remains unchanged through 0.3.1.** The MCP URL also stays the same. Existing working 0.2.2/0.3.0 connections do not need recreation simply to upgrade. Reconnect once after opting into YOLO so each client receives fresh consent for direct changes. For an already failed connector, run the checks and retry; recreate it only if it retains stale metadata or registration settings. Connectors created against 0.1.0/0.2.0 need recreation for the historical issuer change. Restart any sign-in tab opened before the upgrade.

The 0.1.0 plugin accepted WordPress Application Passwords and did not provide an OAuth sign-in service. Selecting OAuth in ChatGPT could therefore produce a registration error such as “Couldn't register with … sign-in service.” Version 0.2.0 adds discovery, automatic client registration, WordPress login/consent and tokens. A firewall or routing issue can also cause a registration failure, so use the new checks if it continues after upgrading.

Do not choose “No authentication” as a workaround. Do not put your WordPress login or Application Password in the OAuth Client ID / Client Secret fields.

## 1. Update the Staging Plugin

1. In WordPress, open **Plugins → Add New → Upload Plugin**.
2. Upload **jalin-mcp-gateway-0.3.1.zip**. If an earlier version is installed, choose the WordPress option to replace the existing plugin. Keep the same plugin directory, `jalin-mcp-gateway`.
3. Activate if WordPress asks. Check that the settings header shows **0.3.1**.
4. Open **Settings → JalinWP → Connection Setup**.

The update preserves existing gateway settings, explicitly enabled accounts, financial mappings, and resource-bound OAuth grants. The repaired connection-list query and additive storage migration do not rotate valid tokens. Requests from before 0.3.0 that lack server-held review context are revoked. A healthy 0.3.0 → 0.3.1 schema upgrade preserves reviewed requests and existing grants; saving a different Change Mode invalidates unfinished requests. Existing grants receive no YOLO permission automatically. When upgrading from 0.1.0, the OAuth storage is created and OAuth starts disabled until you enable it. An already enabled 0.2.0 installation keeps that setting. You do not need to delete the old plugin first. The plugin is still a staging preview; this guide does not assert that the updated build has connected to your host yet.

## 2. Enable Your Account and Choose Access

Click **Enable For My Account**. This enables MCP and OAuth and adds only your signed-in WordPress administrator to the explicitly enabled accounts. Existing access preferences, other explicitly enabled accounts, browser origins, and finance mappings are preserved.

**Your Account** replaces the old all-users checklist. Previously enabled additional accounts appear in the collapsed **Existing Account Access** section, where access can be explicitly removed. The update does not silently enable every administrator or enumerate customers and subscribers. Your account still needs the native WordPress/WooCommerce permissions for the tools you use.

An enabled site shows **MCP Enabled** and **Disable MCP Connection**. If MCP is already enabled but your current account is not, the card shows both **Enable For My Account** and the site-wide disable action. Enabling your account does not enable sensitive data or change the selected Change Mode automatically.

Before connecting, choose the access you need under **Access Controls**, then click **Save Access Controls**:

| Control | Behavior |
| --- | --- |
| **Change Mode → Read Only** | Retrieve permitted data. Clients cannot create, update or apply changes. |
| **Change Mode → Reviewed Changes** | Clients propose edits. Approve each in WordPress, then the requesting connection applies it. Requests expire after 15 minutes. |
| **Change Mode → YOLO Mode (No Dashboard Approval)** | Supported writes execute immediately in their original tool call after validation. Existing OAuth clients need fresh YOLO consent first. |
| **Allow Order, Customer, Comment, and Financial Data Access** | Permit these records and reports, subject to WordPress/WooCommerce permissions. Leave this off for content-only access. |
| **Enable OAuth Sign-In**, inside **Advanced Access** | Allow compatible clients to sign in through WordPress consent. Standard setup enables it automatically; turning it off revokes OAuth connections. |

Each mode and checkbox has permanent help text. The main MCP on/off control is a direct button action and does not require a separate settings save.

For sales analysis, enable the financial-data option and use an account with WooCommerce management permissions. A reporting-only connection can choose Read Only. Consent records the enabled data/write access: after granting additional access, reconnect and approve it. Existing grants are never silently widened. In particular, choosing YOLO does not upgrade existing reviewed grants until each client reconnects and approves direct changes. Subsequent requests observe reduced access; mode changes do not roll back a write already executing or committed.

Connection and Finance forms save only their own settings. An unchanged save preserves unfinished approvals; actual policy or finance changes invalidate them. If another tab changed the same section, the save is rejected and your input is retained for review rather than silently overwriting newer values.

## 3. Check and Copy the Connection URL

Click **Check Connection**. It prepares two independent public documents and checks HTTPS, allowed accounts, REST URLs, storage, OAuth discovery, REST resource metadata, **Proactive MCP Resource Discovery**, and the MCP GET/POST sign-in challenges. A file being created is not proof that a client can retrieve it.

If **Proactive MCP Resource Discovery** warns about Content-Type while confirming matching JSON, the server is likely serving the extensionless file with its default MIME type. The tested MCP SDK accepts that JSON; a stricter client may reject it. Complete the actual client connection and use the trace if it still fails. A warning must not be described as a confirmed connection.

Fix any failed check, then use **Copy URL**. For the site shown in the original screenshot, the endpoint was:

```text
https://firz.my/wp-json/jalin-mcp/v1/mcp
```

Use the URL displayed by your current WordPress installation as the authority. A different site/subdirectory needs its own URL.

Checks run from the WordPress host. Passing checks do not prove ChatGPT or Claude can reach the endpoints, register, sign in, exchange tokens or call tools. The yellow **Final Client Test** is a reminder to verify that flow yourself, not an error or an automatically updated connection status.

## 4. Create the ChatGPT or Claude Connection

In ChatGPT's app/plugin/connector settings, create a custom MCP connection. In Claude, open **Customize → Connectors** and add a custom connector. Exact client menu names can change.

| Field | Value |
| --- | --- |
| Name | A name you recognize, such as **Firz MCP**. |
| MCP Server URL | The URL copied from the plugin. |
| Authentication | **OAuth**. |
| OAuth Client ID | Leave blank. |
| Client Secret | Leave blank. |

Click **Connect**. Automatic client registration supplies the client identity. The browser should take you to your own WordPress site. Sign in with an allowed account, review the consent page and approve. WordPress sends you back to the client that initiated the connection. Its consent page and approval response allow form navigation only to the site's own origin and that request's validated client origin.

If the failed connector continues to show an error after updating and checking, remove and recreate **that connector** with these settings. Its cached metadata or client configuration may be stale. This does not mean deleting the WordPress plugin or its settings. If it still fails, use the trace below before repeating setup.

Supported callbacks are ChatGPT's stable `https://chatgpt.com/connector_platform_oauth_redirect`, its documented `https://chatgpt.com/connector/oauth/{callback_id}` form, and Claude's exact hosted `https://claude.ai/api/mcp/auth_callback`. Hosted Claude includes web, Desktop, mobile and Cowork. Claude Code's local callbacks, guessed alternative domains, arbitrary OAuth clients and wildcard callbacks are not enabled by this release. An existing local client can use the separately documented Application Password bridge.

## 5. Verify With a Read

Start with:

> List the first five WooCommerce products. Do not make any changes.

Then, if you enabled financial-data access:

> Show processing and completed orders created yesterday. Keep currencies separate, fetch all pages and show any missing gateway-fee data. Do not make changes.

Open **Finance Setup** for optional payment processing fee and affiliate commission mappings. The screen explains which WooCommerce totals and taxes already work automatically, lists detected or previously configured gateways, and offers **Test With an Order** for a proposed mapping. Processing costs are separate from customer-added WooCommerce fees. OAuth does not discover provider-specific metadata keys or fetch settlement data from payment providers.

For a **Reviewed Changes** test, propose a draft product or an unpaid test order. Review it under **Review Changes**, then ask the same connection to apply the returned change ID. Approval expires after 15 minutes.

For a **YOLO Mode** test, save that mode, reconnect each client once and approve the WordPress consent page that explicitly says changes run without dashboard approval. The connection list should report **YOLO Active**. Ask for one draft test page; the original tool call returns the applied result and change ID. Do not follow it with `gateway_apply_change`. Your AI client can still ask for its own confirmation.

If a client still returns a review URL, inspect its approved access: an existing reviewed grant intentionally retains review until fresh YOLO consent. Do not retry uncertain writes; repeating the original write call can duplicate changes. Use the returned change ID for status inspection. Design creation keeps PHP validation in YOLO but skips installed Gutenberg JavaScript validation during dashboard review. Open the resulting draft in the native editor to check it. See [YOLO-MODE.md](YOLO-MODE.md).

## Manage or Disconnect Access

In the **OAuth Connections** tab, click **Refresh Connections** to inspect each client, WordPress account, approved access, status, and dates. Separate Claude and ChatGPT grants appear separately even for the same account. Registered client names are display labels, not verified provider identities.

A connection can show **Approval Received — Finish Connecting**, **Authorized — Awaiting First Request**, **Active**, **Reconnect Required**, **Expired**, **Revoked**, or **Authorization Recorded** for older records with incomplete evidence. **Last Authenticated** records authentication, even if the subsequent operation failed. Active does not mean a continuously open connection or prove successful tool execution. A storage error displays **Connections Could Not Be Loaded** rather than an empty list.

Use **Revoke** for one grant or **Revoke All Connections** for all grants and unfinished OAuth sign-ins. These actions leave the MCP gateway enabled. To stop all MCP access on the site, click **Disable MCP Connection** in the status card. It saves the disabled state first, revokes OAuth access and unfinished changes, and preserves your configuration. Re-enable and reconnect to resume. If cleanup fails, the plugin reports the failure and keeps the disabled state; it will not re-enable old OAuth credentials before revocation is complete.

- Access tokens last one hour and refresh automatically when supported by the client.
- Connection grants last 30 days; reconnect after expiry or revocation.
- Turning OAuth off or deactivating the plugin also invalidates OAuth access. Deactivation preserves configuration for a later explicit enable.
- Actual access-policy or finance changes invalidate outstanding write approvals. A no-op save preserves them.
- Removing an explicitly enabled account or reducing its WordPress permissions restricts access immediately.
- A new grant cannot apply a proposal owned by an older connection.
- **Activity Log → Client** distinguishes authenticated OAuth clients, **WordPress Admin**, **Application Password**, and historical **Not Recorded** events. An administrator's review is attributed to the administrator, not to the requesting OAuth client.

The Application Password used by an older local bridge remains separate. Site-wide MCP disable blocks that bridge's gateway requests, but does not revoke its WordPress Application Password or access to unrelated native REST endpoints. Revoke the password on the WordPress profile if it is no longer needed.

## Troubleshooting

If connecting still fails:

1. Run **Check Connection** and read any failures or MIME warning.
2. Under **Troubleshoot a Connection Attempt**, click **Start 15-Minute Connection Trace**.
3. Retry connecting once in ChatGPT or Claude.
4. Return to WordPress and click **Download Connection Report**. Share that JSON report together with the client error and approximate attempt time.

Tracing is off until started. It retains up to 50 summaries, accepts at most four events per second, stops capture after 15 minutes, and expires retained data after 24 hours. WordPress scheduled tasks handle cleanup. The report includes public configuration URLs, fixed check labels/statuses, event time/stage/method/status, fixed error category, authentication scheme and callback family. It excludes credentials, tokens, codes, state, cookies, request/response bodies, client IDs, IP addresses, user agents, account identities and shop data. Starting again replaces the old capture. Nothing is sent automatically.

Only gateway REST requests reaching PHP can appear. Static discovery served by Nginx, the browser's WordPress login/consent navigation, and requests blocked before PHP are invisible. Host self-checks may appear; rapid or concurrent requests may be omitted. An empty trace does not establish that the client sent no requests. See [Connection recovery](CONNECTION-RECOVERY.md) for interpreting it.

The plugin's **Advanced OAuth Settings** panel shows public Issuer, Authorization URL, Token URL, Registration URL and Scope values if your client supports manual configuration. Keep automatic client registration enabled and client credentials blank. Manual metadata cannot bypass a blocked registration, token or login endpoint.

| Symptom | Check / action |
| --- | --- |
| “Couldn't register with … sign-in service” | Confirm version 0.3.1 and use Enable For My Account, run checks, leave client credentials blank, and capture one retry with the trace. Claude's hosted callback is supported from 0.2.2. |
| OAuth discovery fails | Run Check Connection after updating. That explicit action prepares public metadata where possible. If it reports a protected/unwritable directory or file conflict, retain the message. Existing files are preserved. Clear a stale discovery cache if applicable. |
| OAuth discovery passes without a published file | The public document is already reachable through WordPress. Static publication is optional in that case. |
| All old checks pass but ChatGPT says the server does not implement OAuth | Use the retained Proactive MCP Resource Discovery and GET challenge checks introduced in 0.2.2. The old checks did not cover discovery before a 401 response. If the failure remains, capture one retry. |
| Proactive resource discovery shows a Content-Type warning | Matching JSON is reachable, but its MIME type is nonstandard. The tested SDK accepts it; verify the actual client. If that client rejects it, host MIME handling may still need adjustment. |
| A discovery route returns HTML | Inspect redirects, security plugins, staging-password screens or cache pages. The discovery response must be the plugin's JSON document. |
| HTTPS check fails behind a proxy | Configure trusted HTTPS detection in WordPress/the host. Do not add code that trusts arbitrary forwarded headers from visitors. |
| REST URLs fail | Choose a non-Plain format in **Settings → Permalinks**, save, and copy the resulting MCP URL again. |
| Sign-in challenge fails | Let the MCP route return its 401 `WWW-Authenticate: Bearer …` header. Preserve `Authorization` and `WWW-Authenticate` through the proxy/firewall. |
| WordPress says the account is not allowed | Sign in as the administrator you intend to connect, use Enable For My Account, and ensure the account has the required native permissions. Existing explicitly enabled accounts are preserved under Existing Account Access. |
| Products work but order/sales tools are missing | Enable the data-access setting, check WooCommerce permissions and activation, then reconnect to approve the additional access. |
| Edits are unavailable | Choose Reviewed Changes or YOLO Mode, save and reconnect for expanded write consent. Check native permissions and data-access settings. |
| YOLO is selected but a client still asks for WordPress review | Reconnect that client once and approve direct changes on the WordPress consent screen. Existing reviewed grants deliberately retain reviewed access. Check OAuth Connections for YOLO Active. |
| YOLO is active but the AI client asks for confirmation | The plugin removes WordPress dashboard approval. Client-side confirmations are controlled by the AI client. |
| Connection stopped after changing settings or deactivation | If gateway/OAuth was disabled, reconnect after enabling it again. Actual settings changes revoke outstanding write proposals even when the connection remains valid; unchanged saves preserve them. |
| WordPress checks pass but the client cannot connect | Capture a retry, then investigate the observed stage or external access restrictions. Loopback success is not external reachability, and the trace cannot observe requests blocked before PHP. |
| Refresh fails after a long time or repeated retry | Reconnect. Grants expire after 30 days, and reused refresh tokens can revoke a grant. Do not retry uncertain writes blindly. |

### What the Plugin Handles Automatically

For `firz.my`, the issuer introduced in 0.2.1 and retained through 0.3.1 is:

```text
https://firz.my/wp-json/jalin-mcp/v1/oauth/issuer.json
```

Its standard authorization-server discovery URL is:

```text
https://firz.my/.well-known/oauth-authorization-server/wp-json/jalin-mcp/v1/oauth/issuer.json
```

The second document, needed by clients that discover OAuth before seeing an MCP challenge, is:

```text
https://firz.my/.well-known/oauth-protected-resource/wp-json/jalin-mcp/v1/mcp
```

Both documents contain only public URLs and capabilities. Publication is independent: a conflict at one path does not stop preparing the other. The authorization-server filename ends in `.json`; the standard resource filename is extensionless and can receive the host's default MIME type. The plugin reports that distinction. URLs derive from the actual WordPress configuration, including a custom REST prefix. Keep using the **MCP URL** in the connector form.

On a normal root installation, the plugin can identify the web root from WordPress. For WordPress in a subdirectory it also requires the server's document-root mapping to match the installation before writing. It will not guess another directory or overwrite an existing discovery document that it does not own. Only the plugin's own unchanged metadata can be updated; ACME certificate files and other services remain untouched.

A server that blocks public access, requires a staging password, applies a MIME type rejected by the client, or prevents both file publication and WordPress discovery can still require a hosting/access fix. This is not a guarantee of zero host configuration. Actual GridPane, ChatGPT and Claude verification remains a staging acceptance step; the local tests simulate routing.

## Staging Acceptance Checklist

- Upgrade preserves configured users, finance keys and data/write settings.
- Discovery checks pass for the actual public HTTPS site.
- A new ChatGPT or hosted Claude OAuth connector registers with blank client credentials.
- WordPress sign-in and consent return successfully to the selected client.
- A permitted read succeeds; unapproved sensitive/write access is unavailable.
- Existing working 0.2.2/0.3.0 grants remain valid after upgrade with their prior reviewed/read-only access; both clients appear in OAuth Connections.
- Revoking one connection blocks its subsequent requests without revoking the other.
- Disable MCP Connection blocks MCP access; re-enable requires fresh OAuth authorization.
- Activity Log shows the authenticated client and distinguishes administrator review.
- Finance Setup preserves existing mappings and tests an unsaved mapping without mutating an order.
- A separate reviewed test connection can propose, review and apply one harmless change exactly once per change ID.
- Saving YOLO does not widen that grant; reconnecting with explicit YOLO consent makes a harmless draft creation execute directly.
- Switching back to Reviewed Changes makes later writes require review; Read Only denies them. Neither switch reapplies old requests or undoes already committed writes.
- A YOLO draft opens correctly in Gutenberg; the plugin did not run the installed-editor browser review gate before saving.

The release's local checks and their limits are documented in [VALIDATION.md](VALIDATION.md). The full live-host acceptance checklist must be completed on your staging site.

References: [OpenAI MCP authentication](https://developers.openai.com/plugins/build/auth) and [Claude connector authentication](https://claude.com/docs/connectors/building/authentication). Developer implementation notes: [OAUTH-DEVELOPMENT.md](OAUTH-DEVELOPMENT.md).
