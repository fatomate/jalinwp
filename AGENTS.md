# Working On JalinWP

These instructions apply to this repository. Follow the current user request and applicable higher-priority instructions. They document the project's structure and compatibility requirements; they do not add a separate approval process for ordinary development.

## Start Here

Read [README.md](README.md), [CONTRIBUTING.md](CONTRIBUTING.md), and the documentation relevant to the requested change. The current plugin baseline is 0.3.3. Repository setup is in [docs/REPOSITORY-SETUP.md](docs/REPOSITORY-SETUP.md).

Use [the architecture guide](fames-mcp-gateway/docs/ARCHITECTURE.md) for the module map, [0.3.3 upgrade notes](fames-mcp-gateway/docs/UPGRADE-0.3.3.md) for the latest admin behavior, and [validation notes](fames-mcp-gateway/docs/VALIDATION.md) for executed plugin tests and their limits. Versioned handoff reports and older validation directories are historical references.

## Repository Layout

- `fames-mcp-gateway/`: installable PHP plugin, browser assets, documentation, local bridge, and maintained integration cases.
- `test-runtime/`: pinned Node/Playground dependencies, disposable WordPress test wrappers, reference fixtures, and archived evidence. Keep it beside the plugin folder.
- `scripts/`: current repository checks and release packaging, using Python's standard library.
- `.github/`: lightweight CI and contributor templates.
- `docs/`: repository setup and assembly evidence. `docs/history/` retains the original 0.3.3 source manifest.
- `package-0.3.*.py` and root Fames handoff files: historical release helpers and context. Use `scripts/package.py` for new builds.

## Compatibility Requirements

The JalinWP brand is intentionally separate from the installed identifier. Preserve `fames-mcp-gateway/fames-mcp-gateway.php`, the `fames-mcp/v1` REST namespace, OAuth issuer/resource URLs, `FG_*` classes, `fg_*` storage/hooks/nonces, and saved Canvas/template IDs unless the task explicitly includes a designed migration. A brand or UI edit should not rename those internals.

New setups use Read Only (`writes=false`). The stored `write_mode` is a subtype accepting `reviewed` or `yolo` when writes are enabled; `readonly` is a UI choice, not a valid stored subtype. Preserve explicit existing modes during upgrades. Enabling MCP does not opt users into sensitive data access or writes.

Keep these invariants when changing behavior:

- Effective access intersects current settings, the original OAuth consent, and current WordPress/WooCommerce capabilities. New settings cannot silently widen an existing grant.
- Reviewed changes use frozen proposals, ownership checks, expiry, and a single execution claim. YOLO is an explicit mode and still uses authorization, input validation, and audit logging.
- OAuth credentials are hashed at rest, restricted to this gateway, and invalidated by revocation. Access checks read fresh grant/policy state where required.
- Admin writes use POST, the appropriate capability, and an action nonce. Settings saves keep section isolation and conflict checks.
- Connection deletion targets revoked or expired grants. A temporarily restricted connection is not automatically disposable. Audit history survives cleanup.
- Page design is page-scoped; preserve stale-state guards, revision/recovery behavior, and validated Gutenberg serialization. Do not rewrite the global theme for a page-layout request.
- Financial results distinguish missing, invalid, assumed, and recorded-zero data. Keep currency and unit normalization explicit; use WooCommerce CRUD for order storage compatibility.

## Working Practices

Inspect relevant files before editing; use `rg` for searches. Keep changes focused and preserve unrelated user work. Do not reformat the whole codebase for a small fix. Use existing PHP/JS conventions and the scoped Cobalt/Coral brand tokens; admin headings and button labels use Title Case.

Do not run fixture installers or destructive integration cases against a live WordPress site. Use the documented disposable environment and synthetic data. Keep real credentials, customer/order exports, host configuration, and database copies out of commits and test reports. Do not alter GridPane or another host as part of routine plugin verification.

Do not add an automatic release, deploy, or production-mutation step to CI as part of ordinary setup. Publishing and live actions follow the user's actual request and the execution environment's authorization requirements.

## Verification

Run these from the repository root for repository/build changes:

```sh
python3 scripts/check-repository.py
python3 scripts/test-package.py
python3 scripts/package.py
```

For the local bridge:

```sh
node --test fames-mcp-gateway/bridge/test.mjs
```

For plugin behavior changes, choose the meaningful cases in [test-runtime/DEVELOPER-TESTS.md](test-runtime/DEVELOPER-TESTS.md). Install its pinned dependencies and disposable fixture as documented; do not substitute a global jsdom or infer that `npm test` runs every suite. The admin DOM check needs the PHP fixture first:

```sh
cd test-runtime
node run-wp-tests.mjs admin-033-tests.php
node admin-033-dom.cjs
node run-wp-tests.mjs oauth-cleanup-tests.php
```

OAuth changes warrant the security, connection/audit, consent, refresh, and revocation suites. Finance changes warrant mapping and applicable legacy/HPOS tests. Gutenberg changes warrant PHP adapters and canonical WordPress block validation. Admin CSS-only changes need visual desktop/mobile review; DOM assertions do not demonstrate appearance.

Inspect both wrapper status and inner `passed`/`total`/case results. Tests can produce JSON without throwing. Report the exact commands, environment, results, and limits. Existing evidence is historical unless rerun for the modified code. Native MySQL/MariaDB, real hosted-client connectivity, and actual browser checks are separate from Playground/SQLite tests; never label one as the other.

## Releases And Handoff

The canonical packer reads the version from the plugin metadata and emits install and full-source ZIPs in `dist/`. It must include new repository metadata and exclude local runtimes/secrets. New plugin/docs/scripts files are collected automatically; when adding a test-runtime wrapper, update the curated runtime membership in `scripts/package.py` so the source ZIP includes it. Keep bootstrap version, `FG_VERSION`, `readme.txt` stable tag, current release notes, and documentation consistent when cutting a release. Do not bump a plugin version for repository-only housekeeping.

`SOURCE-MANIFEST.json` inside a generated source archive describes that build. Do not treat the archived original manifest as hashes for new edits. The install ZIP keeps the legacy plugin root; the full-source ZIP uses a `jalinwp/` repository root. Test code, CI configuration, and root contributor files are development assets, not plugin runtime files.

Finish work with a concise explanation of the resulting behavior, important file locations, meaningful validation, and anything still unverified. Do not claim a GitHub push, staging installation, or actual OAuth client connection unless it was performed and confirmed.
