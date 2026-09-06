from pathlib import Path
import hashlib, json

root = Path(__file__).resolve().parent
runtime = root / 'test-runtime'
groups = [
    ('YOLO Settings', 'setup-yolo-tests-output.json', 'Mode defaults, strict saves, stale forms, access help and history.'),
    ('YOLO OAuth Consent', 'yolo-oauth-tests-output.json', 'Existing grants, consent snapshots, mode intersections and authenticated request boundaries.'),
    ('YOLO Execution', 'yolo-execution-tests-output.json', 'Direct drafts, canvas blocks, recovery revisions, orders, policy races, audit failure and no repeated apply.'),
    ('Mode-Aware Tool Metadata', 'yolo-metadata-tests-output.json', 'Initialize, tool descriptions and page design validation in all modes.'),
    ('Setup Regression', 'setup-030-tests-output.json', 'Account controls, migration, revocation, concurrency and real OAuth listing.'),
    ('Admin DOM', 'setup-ui-dom-output.json', 'PHP-rendered mode controls, help, labels and submit behavior.'),
    ('OAuth Connections and Audit', 'oauth-connections-audit-tests-output.json', 'Lifecycle, client attribution, schema migration and error handling.'),
    ('OAuth Security', 'oauth-tests-output.json', 'PKCE, consent, redirect/resource binding, refresh replay and permissions.'),
    ('Gateway Auth and Approvals', 'security-tests-output.json', 'Authenticated Application Passwords, review ownership, expiry, tamper and audit boundaries.'),
    ('Design Review Regression', 'page-design-approvals-tests-output.json', 'Reviewed design lifecycle and installed-editor approval gate.'),
    ('HTTP YOLO Lifecycle', 'oauth-http-normal-chatgpt-yolo-output.json', 'SDK discovery, synthetic login/consent, direct draft, readback, status, refresh and replay.'),
    ('HTTP Read-Only Regression', 'oauth-http-gridpane-static-claude-output.json', 'Synthetic Claude callback with local static discovery proxy and read-only consent.'),
    ('PHP Syntax', 'php-lint-0.3.1.json', 'Plugin PHP and selected runtime fixtures; native harness syntax only.'),
    ('JavaScript Syntax', 'js-syntax-0.3.1.json', 'Plugin JavaScript and selected runtime/test scripts.'),
]
rows = []
for label, filename, scope in groups:
    data = (runtime / filename).read_bytes()
    outer = json.loads(data)
    result = json.loads(outer['output']) if isinstance(outer.get('output'), str) else outer
    passed = result.get('passed')
    passed = len(passed) if isinstance(passed, list) else passed
    total = result.get('total', result.get('checks', result.get('assertions')))
    assert outer.get('status', 'passed') == 'passed' and passed == total and total > 0, filename
    rows.append(dict(check=label, file=filename, passed=passed, total=total, scope=scope, sha256=hashlib.sha256(data).hexdigest()))

pending = [
    'Live staging update and actual ChatGPT/Claude OAuth connections',
    'Native MySQL/MariaDB schema migration and concurrent InnoDB page writes',
    'Real browser layout, consent and Gutenberg editing',
    'WordPress 6.6 / PHP 8.1 minimum-version matrix and Kadence/extension matrix',
]
index = dict(version='0.3.1', date='2026-09-05', executed=rows, not_executed=pending)
(runtime / 'release-0.3.1-evidence-index.json').write_text(json.dumps(index, indent=2) + '\n')
text = '''# Validation — 0.3.1 Staging Candidate

Executed 5 September 2026 using disposable WordPress installations and synthetic accounts, drafts and orders. This release adds an explicit YOLO mode. It was not installed on the user's staging site; no host, firewall or GitHub change was made.

## Executed Checks

| Check | Passed | Scope |
| --- | --- | --- |
'''
text += '\n'.join(f'| {r["check"]} | {r["passed"]}/{r["total"]} | {r["scope"]} |' for r in rows)
text += '''

Counts are overlapping units, not a universal coverage total. The complete source includes these exact files plus hashes in `test-runtime/evidence/release-0.3.1-evidence-index.json`. Only this index identifies checks executed for this release; older test names such as `setup-030` are retained where their current fixtures still apply. Older feature suites remain in the source but were not all rerun for this focused update.

Runtime: WordPress 6.8.8, WooCommerce 10.2.2 for order cases, PHP 8.3 through Playground/SQLite, Node 22, MCP SDK 1.30.0 and jsdom 26.1.0. Initial dependency downloads were not repeated. PHP syntax checks use the installed PHP-WASM CLI.

## Findings and Verification

- A newly consenting OAuth connection advertises YOLO mode and creates a draft through the MCP HTTP endpoint in one call. A later request reads the draft and its applied change status. The reviewed apply tool cannot repeat that change.
- Legacy grants default to no YOLO consent. Refresh does not widen them. Enabling site YOLO while consent is pending cannot silently widen that pending approval.
- Effective execution mode requires a completed MCP authentication decision and fresh site policy. Cookie-only admin context does not grant direct execution. The legacy security fixture was updated to model complete authenticated requests instead of only firing the credential hook.
- Fresh paired mode/policy snapshots reject a concurrent downgrade before the handler starts. Mode changes invalidate unfinished requests rather than executing them.
- Direct execution retains native permissions, sensitive-data gates, strict input validation, client-attributed audit and claim-once change records. Audit failures before execution prevent handler side effects. Handler failures are terminal and expose a change ID for inspection.
- Supported Gutenberg canvas creation and layout updates run without browser review in YOLO. PHP validation, frozen input, stale-state rejection and paired recovery behavior remain tested. The reviewed design workflow continues to require its editor-validation step.
- Early HTTP harness assertions were corrected to match the existing MCP `structuredContent.data` envelope and bounded title object. These were fixture mismatches; the final lifecycle passes with actual HTTP draft readback.

## Limits

The HTTP tests use the official MCP SDK for discovery and a synthetic client for login, consent and token exchange. They inspect the documented callback addresses but never send codes to real ChatGPT/Claude. Local static proxy behavior does not establish live GridPane, Nginx, TLS, CDN/WAF, custom login or 2FA compatibility.

Native MySQL/MariaDB and concurrent InnoDB writes were not available. The native harness under `test-runtime/native-030/` now expects OAuth schema 3 and a denied `allow_yolo` default for legacy grants; only its syntax was checked. SQLite does not establish native database correctness.

DOM tests inspect actual PHP-rendered HTML; they are not real browser layout, accessibility, consent submission or Gutenberg editing tests. YOLO intentionally skips the installed-editor JavaScript approval check. Server block adapters remain bounded, but the installed editor or extensions can still reject markup when opened later. Verify draft save/reopen on staging.

An executing handler can finish after a subsequent mode change. The plugin cannot roll back completed writes or external hook effects merely because an administrator toggles a setting. Page design transaction/revision safeguards are specific to that executor; legacy content and WooCommerce handlers are not a universal transaction. A failed or timed-out write may have partial effects. Repeating the original tool call creates a new request and can duplicate effects; inspect status and the target first.

## Staging Acceptance

1. Upload the 0.3.1 install ZIP over the previous plugin on staging.
2. Confirm the prior Read Only or Reviewed Changes setting and existing OAuth connections remain visible.
3. Select Access Controls → Change Mode → YOLO Mode, save, and reconnect each client once to approve direct changes.
4. Create a disposable draft through each client. Confirm one-call execution and an attributed YOLO entry in Activity and Changes & Review.
5. Create a draft canvas page, open/edit/save/reopen it in Gutenberg, inspect the frontend and test a recovery revision on an update.
6. Switch to Reviewed Changes and verify a new write awaits dashboard approval; switch to Read Only and verify new writes are denied. Test connection revocation and the Disable MCP Connection button.

Packaging verifies archive integrity, required plugin members, install/source byte identity and SHA-256 manifests. It does not replace a staging update or the pending native/browser/client gates. See [YOLO Mode](YOLO-MODE.md) and [Page Design](PAGE-DESIGN.md).
'''
(root / 'jalin-mcp-gateway/docs/VALIDATION.md').write_text(text)
print(json.dumps(dict(status='passed', evidence_files=len(rows))))
