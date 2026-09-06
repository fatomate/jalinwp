# Contributing To JalinWP

JalinWP is a public GPL-licensed WordPress plugin. Issues and pull requests are welcome. Contributions can cover functionality, compatibility, tests, documentation or interface improvements.

Participation is covered by the [Code of Conduct](CODE_OF_CONDUCT.md). For suspected security vulnerabilities, follow [SECURITY.md](SECURITY.md). For ordinary issues, describe the behavior, reproduction steps, relevant versions and expected outcome. Remove credentials and customer information from reports.

## Local Setup

Keep `jalin-mcp-gateway/` and `scripts/test-runtime/` beside each other. Use Node.js 22+ and npm to install the locked development dependencies. From the repository root, verify maintained JavaScript with the pinned strict checker:

```sh
npm ci
(cd scripts/test-runtime && npm ci)
npm run typecheck
```

Both installs are required: the strict checker resolves the OAuth smoke test's SDK import from the runtime dependency tree. CI installs both trees before typechecking.

Then prepare the disposable runtime:

```sh
cd scripts/test-runtime
npm ci --prefix design-js-runtime
node setup-fixture.mjs
node run-wp-tests.mjs runtime-smoke.php
```

Initial setup downloads the pinned WordPress/WooCommerce fixture and PHP-WASM components. It refuses to replace an existing populated WordPress fixture. The test runner copies the fixture to a disposable local site; use synthetic accounts, orders and credentials. A live WordPress site and native database service are unnecessary for these suites.

Follow [Developer Tests](docs/DEVELOPER-TESTS.md) for the complete environment, available suites, generated fixtures and optional native MySQL/MariaDB gate. `npm test` runs the WordPress tools suite only; it is not the full test collection.

## Make A Focused Change

Create a branch for the work. Follow the existing PHP/JavaScript style and keep changes scoped to the requested behavior. Explain the problem, resulting behavior and any compatibility impact in the pull request.

The following existing identities are deliberate runtime contracts:

- Plugin directory/bootstrap: `jalin-mcp-gateway/jalin-mcp-gateway.php`.
- REST namespace: `jalin-mcp/v1` and its OAuth issuer/resource URLs.
- `FG_*` classes, `fg_*` hooks/options/tables and the `jalin-mcp-gateway` text domain.
- Saved JalinWP Canvas template identifiers.

Do not rename these as a cosmetic branding change. Changes to persisted formats or permissions need explicit validation.

Fresh setups are Read Only because `writes=false`. The saved `write_mode` describes Reviewed or YOLO behavior when writes are enabled; it is not a replacement for that boolean. Preserve previously saved choices and the intersection of native capabilities, current settings and OAuth consent.

## Verify The Affected Behavior

Use focused checks for the actual risk. These examples run from `scripts/test-runtime/`:

| Changed Area | Relevant Commands |
| --- | --- |
| Admin Tabs, Defaults, Forms | `node run-wp-tests.mjs admin-033-tests.php`, then `node admin-033-dom.cjs` |
| OAuth History Cleanup | `node run-wp-tests.mjs oauth-cleanup-tests.php` |
| OAuth Authorization And Tokens | `node run-wp-tests.mjs oauth-tests.php` and `node run-wp-tests.mjs oauth-connections-audit-tests.php` |
| Change Modes | `node run-wp-tests.mjs setup-yolo-tests.php` and the affected `yolo-*` suites listed in the developer guide |
| Finance Mapping | `node run-wp-tests.mjs finance-admin-tests.php`; use the HPOS/inactive suites when those paths change |
| Gutenberg And Layouts | The `page-design-*`, `design-blocks-*` and design review suites in the developer guide |
| Protocol Integration | `node oauth-http-smoke.mjs --mode=normal --client=chatgpt` |

Run the admin PHP suite before its DOM companion because it creates the form fixtures. Run each named suite once at a time to avoid overlapping output filenames. The separate jsdom runtime is required for DOM checks.

Lint modified PHP and JavaScript files. For example:

```sh
node node_modules/@php-wasm/cli/php-wasm.js -l ../jalin-mcp-gateway/includes/class-admin.php
node --check ../jalin-mcp-gateway/assets/admin.js
```

Saved evidence is historical until a suite is executed against the changed source. State which checks ran, which failed and which were not run. PHP-WASM/SQLite results do not establish native MySQL/MariaDB compatibility; DOM assertions do not establish browser layout or a successful real client connection.

For interface changes, inspect the affected screen on a disposable staging site at desktop and narrow widths. For connection changes, verify the actual host and client flow when such an environment is available. Document remaining checks instead of claiming them complete.

## Build A Release Candidate

From the repository root:

```sh
python3 scripts/check-repository.py
python3 scripts/test-package.py
python3 scripts/package.py
```

Review the generated verification result and the ZIP contents. The outputs belong under `dist/` and are ignored by Git. Keep downloaded dependencies, local database files, runtime copies, secrets and transient logs out of commits. Lockfiles and maintained tests belong in version control.

The included GitHub workflow runs repository/package checks and the local Node bridge tests. It does not run WordPress integration suites or connect to a live site. A local pass is not a GitHub Actions run.

Update user-facing documentation and the changelog when behavior changes. Keep the plugin header, `FG_VERSION` and `readme.txt` stable tag synchronized for an intentional version bump. Upload the installable ZIP as a release attachment only when publishing a release is intended.

## Pull Request Notes

Include the problem, the resulting behavior, executed verification and material remaining limitations. Link any related issue when one exists. Do not include real account passwords, OAuth tokens, customer/order exports or live site databases in a patch. Use clearly synthetic fixture credentials in tests.

Contributions are made under the project's [GPL-2.0-or-later license](LICENSE). Preserve applicable copyright and license notices for code and assets you include.
