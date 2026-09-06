# YOLO Mode — 0.3.1

> Current navigation (0.3.3): use **Connection Setup** to enable/connect, **OAuth Connections** to manage clients and history, and **Access Controls** to choose permissions and Change Mode. See [0.3.3 Upgrade Notes](UPGRADE-0.3.3.md).

> Branding update: version 0.3.2 uses the JalinWP name and logo. Historical version numbers below describe the existing feature implementation. See [Branding and Upgrade Notes](BRANDING.md) and [Current Validation](VALIDATION.md).

YOLO Mode lets connected clients execute supported WordPress and WooCommerce changes without approving each request in the WordPress dashboard. The original write-tool call returns the applied result. Your AI client may still require its own confirmation.

## Choose a Change Mode

Open **Settings → JalinWP → Access Controls**. Choose a radio option under **Change Mode**, then click **Save Access Controls**.

| Mode | Behavior |
| --- | --- |
| **Read Only** | Retrieve permitted content and data. Creation, updates and applying changes are unavailable. |
| **Reviewed Changes** | The write call returns a proposal and review URL. Approve in WordPress, then ask the same connection to call `gateway_apply_change` once. |
| **YOLO Mode (No Dashboard Approval)** | Validate, record and execute the supported write in its original call. No dashboard approval or follow-up apply call is needed. |

Upgrading does not enable YOLO automatically. Existing sites with writes disabled stay Read Only; existing sites with writes enabled stay Reviewed Changes. Enabling your account preserves the selected mode.

## Enable YOLO for Existing OAuth Connections

1. Choose **YOLO Mode (No Dashboard Approval)** and save.
2. Reconnect Claude or ChatGPT once. You do not need to change the MCP URL or issuer.
3. Approve the WordPress consent screen that explicitly describes immediate changes without dashboard approval.
4. Check **OAuth Connections** for the client's approved YOLO access and **YOLO Active** state.
5. Ask for one draft test page or another small staging change. Inspect the result and Activity Log.

Repeat the reconnect for each OAuth client. Existing grants retain their original read-only or reviewed access until fresh consent; refreshing their tokens does not add YOLO permission. If a write still returns a review URL, the connection probably retains reviewed access. The connection list shows which permission was approved.

The optional Application Password bridge uses the selected site mode for its enabled WordPress user and has no separate OAuth consent step. Its credential still inherits native permissions outside the MCP endpoint.

## What Still Applies

- An enabled gateway, explicitly enabled account and valid connection credential.
- Current WordPress/WooCommerce capabilities and per-object permissions.
- The separate order, customer, comment and financial-data setting and OAuth consent ceiling.
- Typed tool schemas, bounded input/output, rate limits and existing tool restrictions.
- A stored change record, verified client attribution, audit recording and a single-execution claim.
- For dedicated page-design tools: strict PHP block adapters, frozen markup, fresh page/template state, transactional storage, exact saved-content checks and paired revision recovery for updates.

YOLO changes execution timing. It does not add arbitrary PHP, SQL, server commands, theme-file editing, payment capture, refund execution or other unsupported operations. WooCommerce status changes can still trigger stock changes, emails and extension hooks.

## Gutenberg Behavior

Reviewed Changes displays a preview and uses the installed Gutenberg JavaScript packages to validate generated markup before dashboard approval. YOLO skips that dashboard/browser gate.

The PHP adapters still validate the supported block subset and serialize canonical markup. The dedicated design write checks that WordPress stored exactly that markup and the requested layout. These checks do not certify every installed editor version, extension or theme. Opening the saved draft in Gutenberg may reveal a compatibility issue later.

`wp_page_design_validate` performs no content write and reports the validation method, effective write mode and whether installed-editor validation is part of the flow. In YOLO it reports **Not Run in YOLO Mode; PHP Validation Only**. Calling a write tool afterward performs the save immediately. Draft remains the default for dedicated page creation unless another permitted status is supplied.

## Results, Retries and Mode Changes

A successful direct call returns its `change_id`, `status: applied`, `execution_mode: yolo`, tool result and audit status. A failure after its record was stored includes the change ID for inspection. `gateway_change_status` reads the record without retrying it.

Do not call `gateway_apply_change` for a YOLO result. That tool only executes an approved reviewed record. A single change ID cannot execute twice, but repeating the original write-tool call creates a new record and can duplicate an order, note, page or other effect. If a response is lost and no ID is available, inspect the target in WordPress and Activity Log before issuing another write.

Actual settings changes invalidate unfinished requests and advance the stored policy revision. Selecting YOLO never auto-executes old reviewed proposals. Switching back to Reviewed Changes makes later writes require review; Read Only denies them. The direct execution path rechecks fresh settings before and after claiming its record. A write already executing or committed is not rolled back by changing the mode, and external hooks may already have run.

Settings saves preserve current configuration and consented flags; they do not erase prior YOLO consent. A grant that explicitly approved YOLO can use it again if the site later re-enables the mode. Revoke that connection to remove its grant permanently, then reconnect with the intended access.

Direct records use the existing 15-minute execution window and retention rules. Change arguments are retained until expiry plus one day; audit metadata is retained for 30 days, subject to WordPress scheduled cleanup. Stored arguments can include supplied content or contact data. Audit entries exclude credentials and response bodies.

## Implementation Map

| File | Responsibility |
| --- | --- |
| `includes/class-admin.php` | Change Mode radios, descriptions, connection access display and YOLO activity labels. |
| `includes/class-settings.php` | Validated mode saves, section fingerprints, conditional updates, revocation of unfinished records and policy revision. |
| `includes/class-core.php` | Reviewed default and fresh settings reads. |
| `includes/class-oauth.php` | Grant schema `allow_yolo`, consent snapshot/render/completion, effective flags and connection projection. |
| `includes/class-auth.php` | Authenticated per-connection effective mode, intersected with fresh site settings. |
| `includes/class-tools.php` | Effective-mode catalog instructions and reviewed/direct write dispatch. |
| `includes/class-approvals.php` | Frozen context, ready/pending records, private direct execution, reviewed-only public apply, policy checks, atomic claim and audit outcomes. |
| `includes/class-server.php` | Mode-aware initialization, status/apply descriptions and tool response envelopes. |
| `includes/class-page-design.php` | PHP-only YOLO validation reporting and retained prepared design execution. |

The plugin release is `0.3.1`; core schema marker remains `0.3.0`, OAuth schema becomes `3`. The grant migration adds `allow_yolo` with default zero, preserving existing registrations, tokens, resource and issuer. Missing `write_mode` defaults to `reviewed`; missing execution mode on an existing valid change context is treated as reviewed. MCP callers cannot supply or override server execution mode.

Do not implement direct execution by invoking a mutating handler outside the central change-record path. Capture mode and policy revision from the same fresh settings snapshot. Retain OAuth consent checks, exact record ownership, audit-before-execution and the compare-and-swap claim. Never convert a pending reviewed record into a direct record when settings change.

## Reproduction and Staging Checks

The source kit includes these disposable integration entry points:

```bash
cd test-runtime
node run-wp-tests.mjs setup-yolo-tests.php
node run-wp-tests.mjs yolo-oauth-tests.php
node run-wp-tests.mjs yolo-execution-tests.php
node run-wp-tests.mjs yolo-metadata-tests.php
```

Use the source kit's runtime setup instructions first. These tests require isolated fixture databases; do not execute their fixture mutations against the user's site. Refer to [VALIDATION.md](VALIDATION.md) for actual executed results and remaining limitations.

On staging, confirm existing grants remain reviewed after upgrade, fresh YOLO consent produces direct execution, changing the mode restricts later writes, read-only consent cannot write, and Activity Log attributes direct changes to the correct connection. Open a generated draft in Gutenberg and test permitted order changes only with staging data. Native MySQL/MariaDB, the actual theme/extensions and live ChatGPT/Claude behavior require staging verification; local SQLite results are not native database or live-client evidence.
