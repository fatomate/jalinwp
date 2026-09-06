# Historical 0.3.3 Admin And Connection Cleanup Notes

> **Sanitized historical reference, not current release guidance.** JalinWP 0.3.3 is being prepared as an initial private GitHub release and is installed fresh; this document preserves the earlier development notes only.

## What Changed

| Area | Behavior In 0.3.3 |
| --- | --- |
| Header | Smaller logo and padding, a solid cobalt bottom border, and the version number alone. The approved logo keeps its cobalt and coral colors. |
| Connection Setup | Contains enable/disable, the connector URL, connection instructions, diagnostics, and tracing. |
| OAuth Connections | Dedicated tab for connection history, refresh, revocation, and cleanup. |
| Access Controls | Dedicated tab for account access, Read Only/Reviewed/YOLO modes, sensitive data access, and advanced options. |
| Finance Setup | Seven large automatic-field cards become a compact “Included With WooCommerce” summary with a “No Mapping Needed” badge. Optional mapping forms keep their existing behavior. |
| Navigation | Six tabs: Connection Setup, OAuth Connections, Access Controls, Finance Setup, Changes & Review, Activity Log. Form redirects and links open the appropriate tab. |

## Connection Cleanup

**Delete** appears on individual revoked or expired connections. It removes that connection record and its stored authorization codes and tokens. An active connection must be revoked before it can be deleted. A temporary “Reconnect Required” state alone does not qualify for deletion.

**Clear Inactive Connections** deletes all revoked or expired connection records, including records beyond the 100 shown in the table. Working connections remain authorized.

**Revoke All Connections** disconnects existing clients and cancels unfinished sign-ins, while retaining connection records.

**Connection Maintenance → Revoke And Clear All Connections** disconnects existing clients, cancels unfinished sign-ins, and deletes their connection records. A browser confirmation describes the effect. MCP stays enabled, and clients must reconnect. A fresh authorization created after the revocation phase is preserved.

All cleanup actions require an administrator, a POST request, and an action-specific WordPress nonce. Cleanup retains client registrations, settings, change records, and Activity Log history. Successful cleanup records an administrator activity entry. Database errors are reported; a partial bulk cleanup reports the number deleted and the number that could not be cleared. Revoked grants remain for retry if their dependent credentials could not be removed.

## Read Only Defaults

A new installation and first-time “Enable For My Account” setup use **Read Only**. Connecting never turns on write access or financial data access by itself. The Access Controls tab now states this explicitly.

Upgrading preserves an administrator’s explicitly saved Reviewed Changes or YOLO choice. To change an existing installation to Read Only, select **Access Controls → Read Only → Save Access Controls**. Current access restrictions are enforced against existing OAuth grants; granting extra permissions still requires fresh OAuth consent.

Implementation detail: `writes=false` means Read Only. The stored `write_mode=reviewed` value describes the subtype used when writes are enabled. Do not replace it with `readonly`; the existing validator intentionally accepts only `reviewed` or `yolo` for that stored subtype.

## Historical Staging Procedure

1. Upload `jalinwp-0.3.3.zip` through WordPress Plugins → Add New → Upload Plugin and activate it as a fresh installation.
2. Open Settings → JalinWP and confirm version `0.3.3` and the six tabs.
3. Confirm Read Only before and after enabling MCP.
4. Check the compact header, wrapping tabs, finance summary, keyboard focus, and long client/account labels on desktop and narrow screens.

The install directory is `jalin-mcp-gateway`. Private `FG_*` classes, `fg_*` storage, and `jalin-mcp/v1` remain implementation identifiers, not an installation migration contract.

## Where To Make Further Changes

| File | Responsibility |
| --- | --- |
| `includes/class-admin.php` | Tab map, header markup, screen rendering, admin form handlers, cleanup feedback, and navigation. |
| `assets/admin.css` | Header size, cobalt border, responsive tabs, connections table and action layout. |
| `assets/admin.js` | Immediate-action duplicate-submit protection and explicit clear-all confirmation. |
| `includes/class-oauth.php` | Connection lifecycle projection, `can_delete`, `delete_connection()`, and `clear_connections()`. |
| `includes/class-finance-admin.php` / `assets/finance.css` | Compact automatic-fields summary and existing optional mapping UI. |
| `includes/class-core.php` / `includes/class-settings.php` | Existing defaults, effective permissions, and settings validation. These behavior files were not changed for this release. |
| `tests/admin-033.php` / `tests/oauth-cleanup.php` | New disposable WordPress integration coverage for this release. |
| `../scripts/test-runtime/admin-033-dom.cjs` | DOM checks for confirmation cancellation, confirmed submit, duplicate-submit prevention, and access-tab initialization. |

The full source archive contains the test wrappers, pinned runtime instructions, and retained historical evidence. Build it with `python3 scripts/package.py`; helpers in `scripts/history/` are historical. See [Historical Validation](VALIDATION.md) for the earlier executed tests and remaining staging checks.
