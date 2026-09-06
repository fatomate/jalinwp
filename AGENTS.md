# Working On JalinWP

## Start Here

Read [README.md](README.md), [CONTRIBUTING.md](CONTRIBUTING.md), and relevant documentation in [docs/](docs/). The plugin baseline is 0.3.3; repository setup is in [docs/REPOSITORY-SETUP.md](docs/REPOSITORY-SETUP.md).

Use [architecture](docs/ARCHITECTURE.md) for the module map and [validation notes](docs/VALIDATION.md) for executed plugin tests and their limits. Historical handoffs and evidence under `docs/history/` and `docs/evidence/` are references, not current validation.

## Layout

- `jalin-mcp-gateway/`: installable PHP plugin, runtime assets, and templates only.
- `scripts/bridge/`: local bridge and tests.
- `scripts/test-runtime/`: disposable WordPress/PHP-WASM harness, cases, and pinned dependencies.
- `scripts/`: repository checks, packaging, source membership, and historical helpers.
- `docs/`: plugin guides, repository documentation, developer/runtime instructions, and historical evidence.

## Identity And Safety

This unreleased plugin has no compatibility or migration obligation. Its public identity is `jalin-mcp-gateway/jalin-mcp-gateway.php` and `jalin-mcp/v1`. Keep private `FG_*` classes and `fg_*` storage/hooks/nonces unless a change requires them; do not rename them cosmetically.

New setups use Read Only (`writes=false`). Stored `write_mode` accepts `reviewed` or `yolo` only when writes are enabled. Enabling MCP never opts users into writes or sensitive-data access.

- Effective access intersects settings, original OAuth consent, and current WordPress/WooCommerce capabilities.
- Reviewed changes retain frozen proposals, ownership checks, expiry, and a single execution claim. YOLO still requires authorization, validation, and audit logging.
- OAuth credentials are hashed at rest and invalidated by revocation.
- Admin writes use POST, capability checks, and action nonces.
- Page design remains page-scoped with stale-state guards and validated Gutenberg serialization.
- Financial results distinguish missing, invalid, assumed, and recorded-zero data.

## Verification

Run repository/build checks from root:

```sh
python3 scripts/check-repository.py
python3 scripts/test-package.py
node --test scripts/bridge/test.mjs
python3 scripts/package.py
```

For plugin behavior, use the appropriate cases in [docs/DEVELOPER-TESTS.md](docs/DEVELOPER-TESTS.md). The admin DOM test needs its PHP fixture first. Inspect both wrapper status and inner `passed`/`total` results. Playground/SQLite, DOM, native database, browser, and hosted-client checks are distinct claims.

Keep downloaded dependencies, disposable sites, generated output, credentials, and `.pi/` out of commits and packages. Do not add automatic release, deploy, or production-mutation steps to CI.
