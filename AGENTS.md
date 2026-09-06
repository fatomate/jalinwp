# Working On JalinWP

## Start Here

Read [README.md](README.md) and [CONTRIBUTING.md](CONTRIBUTING.md) first. Version 0.3.3 is the initial published release in the public [GitHub repository](https://github.com/fatomate/jalinwp); use its tag as the release source reference, not as evidence for later working-tree changes.

Use [architecture](docs/ARCHITECTURE.md) for the module map and [validation notes](docs/VALIDATION.md) for executed plugin tests and their limits. Historical handoffs and evidence under `docs/history/` and `docs/evidence/` are references, not current validation.
For security-sensitive changes, read the [security audit](docs/SECURITY-AUDIT-0.3.3.md) and its residual risks. For packaging or publication, read the [release guide](docs/RELEASE-0.3.3.md); distinguish candidate-time pending gates from verified GitHub release/CI state.

## Layout

- `jalin-mcp-gateway/`: installable PHP plugin, runtime assets, and templates only.
- `scripts/bridge/`: local bridge and tests.
- `scripts/test-runtime/`: disposable WordPress/PHP-WASM harness, cases, and pinned dependencies.
- `scripts/`: repository checks, packaging, source membership, and historical helpers.
- `docs/`: human guides, developer/runtime instructions, and historical evidence. Keep repository entry points and control files (`README.md`, `AGENTS.md`, license, changelog, package/TypeScript configuration) at root.

## Identity And Safety

The 0.3.3 release deliberately broke pre-release public identities without aliases or a certified upgrade path. Its public identity is `jalin-mcp-gateway/jalin-mcp-gateway.php` and `jalin-mcp/v1`. Treat these as released contracts: future changes to identifiers, persisted data or permissions require explicit compatibility decisions and validation. Keep private `FG_*` classes and `fg_*` storage/hooks/nonces unless a change requires them; do not rename them cosmetically.

For an existing pre-release staging installation, prefer a fresh disposable site/database. Reusing a site requires a backup, revoking connections and disabling/deactivating the old plugin before activating the replacement; never activate both together. Retained `fg_*` data means reinstalling is not a settings reset. Explicitly verify Read Only, sensitive access and allowed users, then reconnect clients to the new URL. Inspect discovery ownership/conflicts before removing any `.well-known` files. Do not describe this replacement path as certified.

New setups use Read Only (`writes=false`). Stored `write_mode` is `reviewed` or `yolo` and matters only when writes are enabled; do not store `readonly`. Enabling MCP never opts users into writes or sensitive-data access.

- Effective access intersects settings, original OAuth consent, and current WordPress/WooCommerce capabilities.
- Reviewed changes retain frozen proposals, ownership checks, expiry, and a single execution claim. YOLO still requires authorization, validation, and audit logging.
- OAuth credentials are hashed at rest and invalidated by revocation.
- Admin writes use POST, capability checks, and action nonces. Settings-page GET must not publish discovery files or mutate ownership options; publication stays behind protected setup/check actions, including AJAX. Test rejected requests as well as successful publication.
- Page design remains page-scoped with stale-state guards and validated Gutenberg serialization.
- Financial results distinguish missing, invalid, assumed, and recorded-zero data.

## Verification

For maintained JavaScript changes, install both locked dependency trees with Node.js 22+: `npm ci` at root and `npm ci --prefix scripts/test-runtime`. Root-only installation cannot resolve the OAuth smoke harness SDK. Preserve strict `checkJs` coverage of maintained browser, bridge and harness files; use concrete JSDoc/DOM narrowing rather than suppressions, blanket `any` or scope reductions.

Run relevant checks from root; the complete repository/build gate is:

```sh
npm run typecheck
python3 scripts/check-repository.py
python3 scripts/test-package.py
node --test scripts/bridge/test.mjs
python3 scripts/package.py
```

For plugin behavior, select cases from [docs/DEVELOPER-TESTS.md](docs/DEVELOPER-TESTS.md). Prepare PHP-generated fixtures before DOM/canonical companions and run suites sharing output paths serially. Fixture setup must refuse populated sites. `npm test` is only the WordPress tools suite, not all integration tests. Inspect both wrapper status and inner `passed`/`total` results. Playground/SQLite, synthetic DOM, native database, authenticated browser and hosted-client checks are distinct claims; recorded MariaDB success does not establish separate MySQL or native WooCommerce/HPOS coverage.

Keep downloaded dependencies, disposable sites, generated output, credentials, and `.pi/` out of commits and packages. Do not add automatic release, deploy, or production-mutation steps to CI.

## Release Discipline

Publish only when explicitly requested. Keep version metadata synchronized, resolve material security/review findings, and require green CI on the exact release commit. Verify install/source ZIP membership and fresh activation of the extracted install ZIP. Upload both ZIPs plus `SHA256SUMS` to a draft release, download and compare against the local checksums, then publish. Preserve existing tags/assets rather than silently replacing them. Live deployments require separate authorization.
