# JalinWP 0.3.1 — YOLO Mode Handoff

Date: 5 September 2026. This is the current handoff for the YOLO update, built on the 0.3.0 implementation of the nine earlier improvements. The separate 0.3.0 developer handoff describes that earlier release and is historical when it conflicts with this document.

## Delivered Behavior

The old **Allow Reviewed Changes** checkbox is replaced by **Change Mode**:

| Mode | Behavior of a Supported Write Tool |
| --- | --- |
| Read Only | Reject the write; ordinary permitted reads remain available. |
| Reviewed Changes | Create a pending request. An administrator approves the exact request in WordPress, then the requesting connection calls `gateway_apply_change`. |
| YOLO Mode (No Dashboard Approval) | Execute the change during the original tool call and return its result and change ID. No dashboard review or subsequent apply call is needed. |

YOLO is an explicit administrator setting. Existing installations are not opted in by upgrading. Existing OAuth connections retain the permissions they approved; reconnect once after enabling YOLO to approve direct changes. This one-time connection consent is separate from per-change dashboard approval. The AI client can still impose its own confirmation prompts.

The current mode appears near the connection setup. OAuth rows show approved permissions and whether YOLO is effective. **Changes & Review** includes direct execution history without inventing an approving administrator or displaying approval controls for an applied YOLO record. Activity displays YOLO outcomes alongside the authenticated client's existing attribution.

## Install and Enable

1. On staging, upload `jalin-mcp-gateway-0.3.1.zip` under Plugins → Add New → Upload Plugin and replace the installed plugin when WordPress offers the update.
2. Open Settings → JalinWP → Access Controls.
3. Under Change Mode select **YOLO Mode (No Dashboard Approval)** and click **Save Access Controls**.
4. Reconnect Claude and ChatGPT once, signing in with the enabled WordPress account. The consent page explicitly says that permitted changes can run without WordPress dashboard approval.
5. Ask for a disposable draft. Confirm that it is created in the same call and that its change history and Activity entries identify YOLO execution and the requesting client.

An older write-enabled OAuth grant continues using Reviewed Changes until new consent. An older read-only grant stays read-only. Token refresh never grants YOLO by itself. For an authenticated Application Password connection, the administrator's selected site mode applies without an OAuth consent screen.

Selecting Reviewed Changes or Read Only and saving restricts subsequent execution. The existing Disable MCP Connection button remains available for disabling the gateway. Mode changes invalidate unfinished requests; they do not execute previously pending proposals or undo completed writes. A handler already running can finish after a later setting change.

## Scope and Existing Controls

YOLO affects all registered mutating gateway tools, including supported WordPress content, supported Gutenberg page designs and layouts, and the existing WooCommerce write operations. It does not add arbitrary PHP execution, new order/payment capabilities, raw REST forwarding, or new permissions beyond the registered tools.

Account eligibility, native WordPress/WooCommerce capabilities, enabled private-data access, OAuth consent, strict argument schemas, bounded adapters and activity storage remain enforced. The existing unpaid-order and paid-order restrictions still apply. New content continues to default to draft unless another permitted status is explicitly requested.

For page design, YOLO skips the installed Gutenberg JavaScript review gate because that gate requires the dashboard. Server-side block adapters, input bounds, prepared content, page/template state checks, exact saved-content checks and paired recovery revisions remain in the page executor. The installed editor or extensions may still detect incompatible markup later; staging save/reopen remains necessary. Existing content/commerce handlers do not acquire universal transaction or rollback behavior from YOLO.

## Execution and Response Contract

`FG_Tools::call()` validates the requested operation, resolves the effective connection mode and dispatches to a reviewed proposal or immediate execution. MCP initialize and catalog descriptions tell the client the effective behavior.

For YOLO, `FG_Approvals::execute_direct()` stores a request with a UUID, user/credential owner, exact arguments, integrity digest and server context. The context contains the policy generation, `execution_mode=yolo`, authenticated request attribution and any frozen page-design state. Mode and policy are captured together from fresh settings. A direct row starts as `ready`, is conditionally claimed as `executing`, and finishes as `applied` or terminal `failed`.

Direct access is checked before storage and again around the execution claim. Required audit entries precede side effects. Page writes use the same prepared page executor as reviewed changes; other handlers use the existing validated native operations. The reviewed public apply endpoint cannot execute a YOLO record.

Successful HTTP tool results retain the existing envelope:

```text
result.structuredContent.data = {
  change_id,
  status: "applied",
  execution_mode: "yolo",
  result: <existing tool result>,
  audit_status: "recorded"
}
```

Some existing tool results contain another `data` member, for example the native post result. Do not flatten the response accidentally in clients or tests. `gateway_change_status` returns the execution mode and status for the owning connection, and does not execute anything.

If final audit persistence fails after a successful handler, `audit_status` is `recording_failed_do_not_retry`. Execution failures include a change ID where a request was stored. Hooks can fail after partial effects. Inspect the change and target before issuing another write. Repeating the original tool call creates a new change ID and can duplicate effects; claim-once handling prevents applying the same stored change twice, not all duplicate user requests.

## Settings and Database Compatibility

`fg_settings.writes` remains the compatibility gate. New `write_mode` is `reviewed` or `yolo`, defaulting to `reviewed` when absent. The UI submits `change_mode=readonly|reviewed|yolo` and translates it into these persisted values. The read-only UI selection stores `writes=false` and `write_mode=reviewed`. Section fingerprints include the mode and prevent stale forms from overwriting newer access changes.

OAuth schema version **3** adds `allow_yolo TINYINT(1) NOT NULL DEFAULT 0` to grant records. Existing IDs, token records and consent data are retained by the additive migration. A pending consent request snapshots its requested permissions, and approval intersects that snapshot with current policy; a settings change cannot silently broaden the approved grant. The core change/audit table schema marker remains **0.3.0**, because this release stores mode in existing server context rather than adding core columns.

Effective mode requires a completed authenticated MCP request, matching current user and credential, fresh enabled site policy, account eligibility and original OAuth consent. A cookie-only administrator context is not an authenticated YOLO request. OAuth bearer identity remains confined to the gateway endpoint and does not grant authentication to ordinary WordPress REST endpoints.

## Where to Change the Implementation

Paths below are relative to `jalin-mcp-gateway/` unless stated otherwise.

| File | Responsibility |
| --- | --- |
| `includes/class-settings.php` | Mode defaults, fresh snapshots, allowed values, section fingerprint and unfinished-request invalidation. |
| `includes/class-admin.php`, `assets/admin.css` | Radio controls, active mode, connection permissions, history and human-readable activity labels. |
| `includes/class-core.php` | Shared defaults, fresh persisted-settings reader and existing schema/audit machinery. |
| `includes/class-oauth.php` | Schema 3 migration, consent snapshot, new grant flag and connection listing. |
| `includes/class-auth.php` | Authenticated request boundaries and effective write mode. |
| `includes/class-tools.php` | Central mode dispatch, validation and catalog descriptions. |
| `includes/class-approvals.php` | Reviewed proposals, direct request storage, execution claim, policy checks, audit and status. |
| `includes/class-server.php` | MCP instructions, special status/apply tools and response envelope. |
| `includes/class-page-design.php` | Mode-aware validation metadata and existing page-design executor. |
| `includes/class-design-review.php` | Dashboard design history and the reviewed-only browser validation gate. |
| `includes/class-wp-tools.php`, `includes/class-wc-tools.php` | Native operation handlers and mode-neutral descriptions. |
| `docs/YOLO-MODE.md`, `docs/VALIDATION.md` | User behavior and exact current validation evidence. |

When adding a mutating operation, register it through `FG_Tools` with a strict schema and native capability checks so both modes use the same execution boundary. Do not add a client-provided `skip_approval` argument. Do not infer verified provider identity from an OAuth registration label.

## Tests and Evidence

Current focused functional results: YOLO settings **37/37**, YOLO OAuth **20/20**, direct execution **16/16**, mode metadata **3/3**, setup regression **49/49**, admin DOM **12/12**, OAuth connections/audit **26/26**, OAuth security **41/41**, gateway authentication/approvals **17/17**, reviewed page design **10/10**, synthetic HTTP YOLO **32/32**, and synthetic HTTP read-only **29/29**. Counts describe overlapping assertions/cases and should not be summed into a coverage claim. PHP and JavaScript syntax checks are recorded separately.

The complete source archive contains the plugin source, test cases, runtime wrappers, pinned dependency manifests, packaging scripts, documentation and current evidence index. Dependencies, downloaded WordPress/WooCommerce, databases, credentials and transient runtime directories are excluded. Preserve the sibling layout `jalin-mcp-gateway/` plus `scripts/test-runtime/`; follow `docs/DEVELOPER-TESTS.md`.

The new files are `tests/setup-yolo.php`, `tests/yolo-execution.php`, `tests/yolo-metadata.php`, their runtime wrappers, and `scripts/test-runtime/yolo-oauth-tests.php`. The HTTP harness accepts `--write-mode=yolo`. Security fixtures were corrected to complete the permission callback after Application Password authentication; production access checks were not weakened to satisfy tests.

Testing used disposable WordPress 6.8.8, PHP 8.3/SQLite and WooCommerce 10.2.2, with actual native WordPress APIs and synthetic data. HTTP tests use the official MCP SDK for discovery, then a synthetic login/consent client; real hosted callback addresses are inspected but never contacted with an authorization code. jsdom checks inspect PHP-rendered HTML and do not establish real browser behavior.

## Remaining Staging Checks

Native MySQL/MariaDB migration, concurrent native InnoDB page writes, real browser consent and Gutenberg editing, actual Claude/ChatGPT reconnects, live GridPane routing, custom login/2FA and the broader version/theme matrix remain unverified. The native harness was updated for schema 3 but only syntax-checked. No staging update, live write, firewall adjustment, GitHub push or repository creation occurred in this turn.

On staging, verify the update preserves current settings and client rows, obtain YOLO consent once, create/read a draft through each client, inspect client-attributed YOLO history, open/save/reopen a Gutenberg canvas design, test a recovery revision, then verify switching back to Reviewed Changes and Read Only. The existing permission and private-data settings should continue to bound all three modes.
