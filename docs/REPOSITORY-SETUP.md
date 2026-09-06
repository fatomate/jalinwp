# JalinWP Repository And Release Setup

The public repository is [fatomate/jalinwp](https://github.com/fatomate/jalinwp). JalinWP 0.3.3 is the initial GitHub release; it is a fresh-install plugin, not an upgrade or migration release.

## Verify The Release Candidate

From the repository root:

```sh
python3 scripts/check-repository.py
python3 scripts/test-package.py
node --test scripts/bridge/test.mjs
python3 scripts/package.py
```

The current version produces:

- `dist/jalinwp-0.3.3.zip` — install this in WordPress.
- `dist/jalinwp-0.3.3-source.zip` — development source, tests, tools, documentation, and retained historical evidence.

The install ZIP contains only the `jalin-mcp-gateway/` plugin runtime and its distribution metadata. The source ZIP receives a generated `SOURCE-MANIFEST.json`; it is not tracked in the repository. Generated dependencies, disposable fixtures, credentials, `.pi/`, and regenerated `scripts/test-runtime/brand-preview/` files are excluded.

## Initial Public Release

Before creating the public `v0.3.3` release, review the exact commit and the generated ZIP contents. Attach the install ZIP and, if useful, the source ZIP. GitHub-generated source archives are development archives, not WordPress install packages.

The included workflow runs repository/package checks and Node bridge tests without live credentials. It does not run WordPress integration suites, native database checks, browser checks, or hosted-client connectivity.

For ongoing work, read [Contributing](../CONTRIBUTING.md), [Agent Guidance](../AGENTS.md), and [Developer Tests](DEVELOPER-TESTS.md). Historical evidence is retained under [evidence/](evidence/README.md) and does not prove current validation.
