# JalinWP 0.3.3 Source Layout

JalinWP is a private repository at <https://github.com/fatomate/jalinwp>. Version 0.3.3 is its intended initial GitHub release and is installed as a fresh plugin; there is no upgrade or migration path in this source tree.

Start with:

- [Plugin Guide](PLUGIN-GUIDE.md) for supported operations and boundaries.
- [Developer Tests](DEVELOPER-TESTS.md) for pinned dependencies and disposable fixtures.
- [Runtime Notes](RUNTIME.md) for the PHP-WASM harness.
- [Native Database Gate](NATIVE-030.md) for the optional isolated native PHP/MySQL or MariaDB check.
- [Validation](VALIDATION.md) for historical results and remaining verification limits.
- [Retained Evidence](evidence/README.md) for sanitized, non-current evidence.

Keep `jalin-mcp-gateway/` and `scripts/test-runtime/` side by side. Run `node run-wp-tests.mjs brand-preview-tests.php` from `scripts/test-runtime/` to regenerate the ignored `brand-preview/` fixture. Build archives from the repository root with `python3 scripts/package.py`; helpers in `scripts/history/` are historical and not release entry points.

The source archive receives a generated `SOURCE-MANIFEST.json` during packaging; it is not a checked-in source manifest. Dependencies, disposable runtime copies, credentials, generated previews, and transient logs are excluded.
