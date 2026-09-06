# Developer Integration Tests — 0.3.3

Keep `fames-mcp-gateway/` and `test-runtime/` side by side after extracting the source kit. The plugin directory contains maintained test cases; this runtime contains disposable WordPress setup, wrappers, the runner, and selected development evidence.

## Install the Pinned Runtimes

Requires Node.js 22+, npm, and internet access for initial downloads. From `test-runtime`:

```sh
npm ci
npm ci --prefix design-js-runtime
node setup-fixture.mjs
```

The main lockfile pins the Playground/PHP-WASM/MCP SDK environment. The separate `design-js-runtime/package-lock.json` pins **jsdom 26.1.0**, used by the admin DOM checks and canonical block tests. Do not substitute a globally installed DOM package. The block tests load the JavaScript block packages bundled with the downloaded WordPress fixture rather than claiming an arbitrary npm Gutenberg version matches the editor.

Setup downloads WordPress 6.8.8 and WooCommerce 10.2.2 and prepares PHP 8.3 with WordPress SQLite Integration. WooCommerce remains inactive until a test activates it. Setup refuses to overwrite an existing populated `fixtures/wordpress`; move that directory aside explicitly if rebuilding it is intended. A fresh portable setup was not repeated during final packaging.

The source kit excludes downloaded WordPress/WooCommerce installations, `node_modules`, databases, and transient run directories. Neither native PHP nor a database daemon is needed for the Playground suites below; the separate native database gate does require them.

## Current 0.3.3 Admin And Cleanup Checks

Run from `test-runtime` after installing the pinned runtimes:

```sh
node run-wp-tests.mjs admin-033-tests.php
node admin-033-dom.cjs
node run-wp-tests.mjs oauth-cleanup-tests.php
node run-wp-tests.mjs brand-preview-tests.php
```

Run `admin-033-tests.php` before the DOM command; it generates the actual rendered form fixtures in `admin-033-fixtures/`. The new suites exercise tab separation, all admin action destinations and guards, effective Read Only first setup through actual consent/token issuance, preservation of explicitly saved modes, cleanup and failure behavior, and confirmation/duplicate-submission handling. The cleanup suite uses real disposable database rows, including a batch larger than the 100-row UI limit and an authorization interleaved after revoke-all.

The brand fixture renders six actual tabs plus the disabled setup state into `brand-preview/`; exported forms cannot submit. These PHP/DOM results do not establish visual browser appearance. Current executed results are documented in `../fames-mcp-gateway/docs/VALIDATION.md` and `evidence-0.3.3/`.

## Existing 0.3.1 Feature Suites

Run from `test-runtime`, one named test at a time:

```sh
node run-wp-tests.mjs setup-yolo-tests.php
node run-wp-tests.mjs yolo-oauth-tests.php
node run-wp-tests.mjs yolo-execution-tests.php
node run-wp-tests.mjs yolo-metadata-tests.php
node run-wp-tests.mjs setup-030-tests.php
node run-wp-tests.mjs oauth-connections-audit-tests.php
node run-wp-tests.mjs finance-admin-tests.php
node run-wp-tests.mjs finance-admin-hpos-tests.php
node run-wp-tests.mjs finance-inactive-tests.php
node run-wp-tests.mjs page-design-tests.php
node run-wp-tests.mjs page-design-approvals-tests.php
node run-wp-tests.mjs design-blocks-tests.php
node design-blocks-canonical-tests.cjs
node ../fames-mcp-gateway/tests/admin-ui-030.mjs ui-preview design-js-runtime setup-ui-dom-output.json
```

Run `setup-030-tests.php` before the admin DOM command: it generates the actual PHP-rendered HTML and local CSS/JS fixtures under `ui-preview/`. Run `design-blocks-tests.php` before the canonical block command: it writes `design-blocks-fixtures.json` from the PHP block adapters.

| Suite | Purpose |
| --- | --- |
| `setup-yolo-tests.php` | Explicit Read Only / Reviewed / YOLO modes, legacy defaults, invalid-mode rejection, versioned saves, Finance isolation, no-op behavior, mode-specific client/history output, and POST/nonce/capability guards. |
| `yolo-oauth-tests.php` | OAuth schema 3 migration, default-denied direct-write consent, retained legacy grants/tokens, consent-time mode changes, refresh, per-request policy narrowing, and capability/data controls. |
| `yolo-execution-tests.php` | Direct execution without dashboard approval, preserved reviewed mode, request identity, schema/capability/policy checks, single-execution claims, audit failures, and page-design constraints. |
| `yolo-metadata-tests.php` | MCP tool descriptions and write annotations reflect the connection's effective execution mode. |
| `setup-030-tests.php` | One-click enable/disable, persistence and revocation failure handling, no-op saves, scope isolation, concurrent-write conflicts, stale-cache protection, real OAuth listing-to-admin rendering, client escaping, access descriptions, and POST/nonce/permission guards. |
| `oauth-connections-audit-tests.php` | Real OAuth grants, lifecycle evidence, preserved credentials, client attribution, request context, listing failures, and additive migrations. |
| `finance-admin-tests.php` / `finance-admin-hpos-tests.php` | Mapping validation, current-snapshot saves, guided admin forms, extraction preview, finance policies, and legacy/HPOS order storage. |
| `finance-inactive-tests.php` | WooCommerce-inactive rendering and guarded finance actions. |
| `page-design-tests.php` | Page/layout discovery, draft creation, updates, stale state, and recovery behavior. |
| `page-design-approvals-tests.php` | Proposal review/apply ownership, frozen design payloads, edit conflicts, recovery, and failure handling. |
| `design-blocks-tests.php` | Bounded block adapters, supported structures, attributes, unsafe input rejection, and PHP-generated fixtures. |
| `design-blocks-canonical-tests.cjs` | WordPress-bundled JS save/validation, intended attributes, serialize/reopen round trips, and rejected invalid block markup in jsdom. |
| `admin-ui-030.mjs` | Linked checkbox/radio help, three mutually exclusive change modes, explicit YOLO form value, repeated-submit prevention, busy state, Title Case diagnostics, and initialization without the connection-check form. |

The 0.3.1 development run recorded **37/37 YOLO settings**, **20/20 YOLO OAuth**, **16/16 YOLO execution**, **3/3 YOLO metadata**, **49/49 setup**, and **12/12 admin DOM assertions**. Existing security and OAuth regression suites passed **17/17** and **41/41** respectively. Suite names containing `030` are retained so existing commands continue to work; they contain the current radio-mode expectations. Consult the packaged 0.3.1 validation report and evidence manifest for the final run and source hashes.

Current setup coverage calls the real OAuth listing method and renders seeded clients through the real admin table. It supersedes `admin-connection-tests.php`, which is a **historical 0.2.2 suite** with stubbed OAuth listings and obsolete whole-settings form assumptions. Earlier 0.3.0 results are prior evidence only unless a suite was explicitly rerun for 0.3.1. Neither the presence of an old result file nor its original pass status proves coverage of this build.

The DOM fixtures are synthetic and prevent form submission; they do not access a real WordPress site. **jsdom assertions are not browser layout, accessibility, authenticated browser, or live Gutenberg editing results.** The actual browser visual gate was not completed in this workspace. Opening, editing, saving, and reopening the page in the installed Gutenberg editor remains a staging acceptance requirement.

## Existing Feature and Protocol Regression Suites

```sh
node run-wp-tests.mjs wp-tools-tests.php
node run-wp-tests.mjs wc-tools-tests.php
node run-wp-tests.mjs analytics-tests.php
node run-wp-tests.mjs analytics-hpos-tests.php
node run-wp-tests.mjs security-tests.php
node run-wp-tests.mjs oauth-tests.php
node run-wp-tests.mjs connection-tests.php
node run-wp-tests.mjs discovery-publisher-tests.php
node run-wp-tests.mjs connection-trace-tests.php
node oauth-http-smoke.mjs --mode=normal --client=chatgpt
node oauth-http-smoke.mjs --mode=gridpane-static --client=chatgpt
node oauth-http-smoke.mjs --mode=gridpane-static --client=claude
node oauth-http-smoke.mjs --mode=blocked-root --client=chatgpt
node oauth-http-smoke.mjs --mode=normal --client=chatgpt --write-mode=yolo
node oauth-http-smoke.mjs --mode=gridpane-static --client=claude --write-mode=yolo
```

These commands document the available checks; consult the packaged validation report for which commands were actually rerun against the final build. Historical output filenames are not proof of a current pass. `npm test` still runs only the WordPress tools suite.

The HTTP smoke script uses pinned `@modelcontextprotocol/sdk` 1.30.0 for proactive and challenge-led OAuth discovery, then an explicit HTTP client for the remaining flow. `normal` exercises PHP routing; `gridpane-static` simulates a server serving the plugin-published documents directly; `blocked-root` expects discovery failure when standard metadata is unavailable. The default `--write-mode=read_only` writes `oauth-http-MODE-CLIENT-output.json`. Optional `--write-mode=yolo` additionally checks direct-write consent and execution, writing `oauth-http-MODE-CLIENT-yolo-output.json` so the read-only result is preserved. The script creates a temporary WordPress site with a synthetic login/consent session and never contacts ChatGPT, Claude, or a real shop.

Static mode deliberately serves the extensionless protected-resource file as `application/octet-stream` to exercise SDK parsing. Passing this test does not prove strict-client MIME compliance, a real GridPane deployment, browser CSP enforcement, or production cookie behavior. Diagnostics use controlled responses; publisher tests use temporary filesystem paths. Trace coverage is limited to requests reaching PHP. The HPOS wrappers use a test-only SQLite LIMIT compatibility shim; this is not native database validation.

## Native MySQL and MariaDB Gate — Not Run Here

See `native-030/README.md` for the isolated runner and prerequisites. Its directory name is retained; its current assertions expect OAuth schema **3**, including `allow_yolo`, while the unchanged core schema marker remains `0.3.0`. **The native schema-3 gate was not executed against either database family in this workspace.** Native PHP with `mysqli`, local MySQL/MariaDB servers, and container runtimes were unavailable. PHP-WASM syntax/guard checks do not count as native database evidence.

From the source-kit root, on a local development machine with native PHP and an isolated database service:

```bash
export FG_NATIVE_WP_CORE_DIR="/absolute/path/to/unpacked/wordpress"
export FG_NATIVE_PLUGIN_DIR="$PWD/fames-mcp-gateway"
export FG_NATIVE_DB_USER="your_local_test_user"
export FG_NATIVE_DB_PORT="3306"
read -r -s -p 'Local Test Database Password: ' FG_NATIVE_DB_PASSWORD
export FG_NATIVE_DB_PASSWORD
php test-runtime/native-030/run.php > native-mysql-031-result.json
```

Repeat against a separate local MariaDB service and save `native-mariadb-031-result.json`. The runner only accepts a loopback service or an explicit local Unix socket; it generates, installs into, and removes a fresh database. Do not point a tunnel at a live database. A pass requires exit code zero, `status: "passed"`, all gate cases passing, and `disposable_database_removed: true`.

The native gate covers real listing SQL, additive schema creation/migration, marker verification, retained tokens and grants, audit attribution, idempotence, and injected migration failures. It does not replace native WooCommerce HPOS coverage, Kadence/block-theme rendering, browser/editor checks, or actual OAuth connections through both clients.

## Runner and Syntax Checks

Every WordPress test copies the fixture to a disposable temporary site. Plugin source mounts at `/wordpress/wp-content/plugins/fames-mcp-gateway`; this runtime mounts at `/test-results`. Temporary cleanup retries at most three times for a late Playground mount flush. Test mutations must remain synthetic and guarded by `FG_TEST_DISPOSABLE` where required.

The runner exits nonzero for assertion failure, exceptions, PHP fatal errors, incomplete or missing results, or a nonzero Playground exit. `<test-name>-output.json` records status and captured output. Run a named suite once at a time to avoid overwriting its result. Some suites also generate separate evidence or markup fixtures. Treat `evidence/` as a snapshot of the documented development run, not a test runner.

```sh
node run-wp-tests.mjs runtime-smoke.php
node run-wp-tests.mjs core-runtime-smoke.php
node node_modules/@php-wasm/cli/php-wasm.js -l ../fames-mcp-gateway/fames-mcp-gateway.php
node --check ../fames-mcp-gateway/assets/admin.js
node --check ../fames-mcp-gateway/assets/finance.js
node --check ../fames-mcp-gateway/assets/design-validation.js
node --test ../fames-mcp-gateway/bridge/test.mjs
node node_modules/@php-wasm/cli/php-wasm.js ../fames-mcp-gateway/tests/analytics.php
```

Lint every changed PHP file, not only the bootstrap. `php-lint-0.3.1.json` and `js-syntax-0.3.1.json` record syntax-only results with source SHA-256 values; passing syntax does not execute the code or establish native database compatibility. Standalone PHP-WASM can report a different PHP version from the WordPress runner. Complete the staging checks in the plugin's `docs/VALIDATION.md` before production use, including the actual host, browser, editor, theme, database, WooCommerce configuration, and both OAuth clients.

The runner now mounts a private per-run results directory, then copies generated artifacts back after PHP exits. This prevents concurrent Playground instances from flushing stale copies over another test's JSON result. Input scripts are never copied back. Run the integrated design-review DOM check after generating block fixtures:

```sh
node design-review-dom-tests.cjs
```

This verifies approval enablement and digest assignment with the installed WordPress packages; it is not a browser test.
