# Validation — Initial JalinWP 0.3.3 Release

Recorded 6 September 2026 against the cleaned uncommitted candidate derived from `88b80bc9035cd146fc66d9cadabb785d550cfb3f`. The release **tag** is the intended final source identity; this document does not treat a working-tree hash as a published release. Packaged manifests record file hashes when archives are built. Files under `docs/evidence/` and `docs/history/` are sanitized historical references, not proofs for this candidate. Final review and publication gates remain separate from these local checks.

Inner `passed`/`total` below come from current `scripts/test-runtime/*-output.json` for **known executed** suites. WordPress PHP wrappers store JSON in `output`; `passed` is sometimes a count and sometimes an array of assertion names (WooCommerce tools, analytics). Wrapper `status` is not by itself an inner count.

## WordPress Integration (parent-executed)

Disposable environment: WordPress 6.8.8, PHP-WASM 8.3.32, SQLite Integration, WooCommerce 10.2.2 where activated. Suites run via `node run-wp-tests.mjs <name>.php` from `scripts/test-runtime`.

| Suite | Inner result |
| --- | --- |
| Fresh install identity/defaults | 7/7 |
| Security | 17/17 |
| OAuth | 41/41 |
| OAuth connections/audit (lifecycle) | 26/26 |
| OAuth cleanup | 16/16 |
| Connection diagnostics | 53/53 |
| Connection trace | 50/50 |
| Admin 0.3.3 | 59/59 |
| Setup | 49/49 |
| YOLO settings | 37/37 |
| YOLO consent | 20/20 |
| YOLO execution | 16/16 |
| YOLO metadata | 3/3 |
| WordPress tools | 13/13 |
| WooCommerce tools | 24 named assertions in `passed` (wrapper passed) |
| Finance admin, posts storage | 62/62 |
| Finance admin, HPOS | 62/62 |
| Finance without WooCommerce | 3/3 |
| Analytics, posts storage | 34 named assertions in `passed` (wrapper passed) |
| Analytics, HPOS | 34 named assertions in `passed` (wrapper passed) |
| Page design | 18/18 |
| Page-design approvals | 10/10 |
| Block adapters | 38/38 |

DOM (parent): admin **8/8**, setup **12/12**, WordPress-bundled canonical block validation/save/reopen **55/55**, design-review **18/18**.

Fixture setup downloaded the pinned environment; repeating setup against a populated fixture was rejected. Initial missing blueprints, relocated paths, obsolete preview assertions and the historical admin-connection fixture were repaired, not waived. The modernized `admin-connection-tests.php` replaces that obsolete 0.2.2 failure (broken `FG_Settings` section fingerprints / empty listing). Counts for the repaired suite are in the worker rerun table below.

## Worker Reruns For This Close-Out

The authorized retry worker independently reran discovery admin/POST/AJAX boundary, publisher filesystem, modernized admin-connection, and admin 0.3.3 on the current tree after the GET/AJAX regression strengthening. Each wrapper passed; nested cases all passed. PHP-WASM syntax checking also passed for `discovery-admin-tests.php`.

| Suite | Inner result |
| --- | --- |
| Discovery admin/POST/AJAX boundary | 11/11 |
| Discovery filesystem publisher | 41/41 |
| Modernized admin connection integration | 26/26 |
| Admin 0.3.3 (rerun) | 59/59 |

## JavaScript And HTTP

- Root `npm run typecheck` passed independently for parent and retry worker with `allowJs`, `checkJs`, and `strict`; no source suppressions or narrowed include list. Install both root and `scripts/test-runtime` dependencies with `npm ci` in each directory first: the HTTP harness imports the runtime SDK. CI now performs both installs before typechecking.
- Parent focused LSP on all **14** maintained JavaScript files: **zero** diagnostics. The initial 251 LSP diagnostics and subsequent 282 strict compiler diagnostics were resolved through project configuration, concrete host declarations, JSDoc and DOM/type narrowing—not suppressed or waived. The installed adapter's JavaScript language mapping is outside the plugin ZIP.
- Parent reran `node --test scripts/bridge/test.mjs`: **16/16** passed.

Source-type workers and parent executed these local HTTP profiles; the retry worker inspected nested results but did not rerun HTTP:

| Local HTTP profile | Result |
| --- | --- |
| Normal ChatGPT | 28/28 |
| Normal Claude (parent) | 28/28 |
| Simulated static host, ChatGPT (parent) | 30/30 |
| Simulated static host, Claude | 30/30 |
| Normal ChatGPT, YOLO | 33/33 |
| Simulated static host, Claude, YOLO (parent) | 35/35 |
| Blocked root (parent) | Expected negative: exit 1, wrapper `failed`, exact error `Expected complete OAuth authorization server metadata` after 5 successful prerequisites |

Parent independently asserted the blocked-root failure as the intended negative scenario, not a successful connection. These HTTP results are parent/earlier-worker execution evidence corroborated by artifact inspection, not new retry-worker runs. Published-archive SHA checks remain pending. HTTP tests use a local proxy and synthetic callbacks. No code is sent to a real ChatGPT/Claude callback and no hosted account is connected.

## Native Database And Visual Checks (parent)

The native gate `scripts/test-runtime/native-030/run.php` passed **14/14** using PHP **8.3.33**, `mysqli`, and MariaDB **11.4.13** in disposable Docker (`netnone` / tmpfs, readonly repo). The generated database was removed (`disposable_database_removed=true`). This is native core/OAuth schema and failure handling, **not** native WooCommerce or HPOS.

Actual PHP-rendered inert previews were visually read at **1440×1000** and **390×844** (connect + access): logo, tabs and Read Only layout were correct. Forms were inert. This is not authenticated admin, Gutenberg editor, accessibility certification, real hosted-client or TLS testing.

## Repository And Distribution Gates

This close-out: root `npm run typecheck` passed; `python3 scripts/check-repository.py` passed (191 source files, 109 relative Markdown links); `python3 scripts/test-package.py` **8/8**; `git diff --check` clean on the inspected diffs. Exact downloaded-asset SHA-256 of a GitHub release is **not** claimed. Publication is not established by this document.

Parent activated the extracted install ZIP in a fresh disposable WordPress fixture and reran its identity/defaults suite: **7/7**. Package regression tests also establish extracted-source rebuild reproducibility. A clean-source mirror initially failed strict typechecking with only root dependencies; installing the pinned test-runtime dependencies resolved it, and CI now includes that prerequisite. Parent reran `npm audit` in all three dependency trees: zero findings. The release workflow additionally requires final Standards/Spec acceptance, CI at the exact reviewed commit SHA, and tag/assets/downloaded-checksum verification; final publication evidence belongs to the GitHub release and Actions records.

## Remaining Limits

No live deployment, real hosted-client connection, real TLS/CDN/WAF acceptance, full WordPress/PHP/theme matrix, authenticated browser consent, or native WooCommerce HPOS test is claimed. See [Security Audit](SECURITY-AUDIT-0.3.3.md) for findings and residual risks, and [Developer Tests](DEVELOPER-TESTS.md) for commands. Suite counts overlap and are not a coverage percentage.
