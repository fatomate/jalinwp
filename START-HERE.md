# JalinWP 0.3.3 Complete Source

Use **jalinwp-0.3.3.zip** for staging installation. This source archive is the development kit and is not an installable plugin ZIP.

The plugin was previously named Fames MCP Gateway. The source intentionally retains the `fames-mcp-gateway/` directory and bootstrap filename for in-place upgrades. Existing endpoints, issuer, data and permission modes are retained.

Start with:

- `fames-mcp-gateway/docs/BRANDING.md`: the earlier 0.3.2 branding changes and identity compatibility.
- `fames-mcp-gateway/docs/UPGRADE-0.3.3.md`: new tabs, cleanup semantics, defaults, upgrade steps, and code map.
- `fames-mcp-gateway/docs/VALIDATION.md`: actual 0.3.3 checks and remaining staging/browser work.
- `fames-mcp-gateway/docs/BRAND-GUIDELINES.md`: selected logo/palette and full design reference.
- `test-runtime/brand-preview/`: sanitized real PHP-rendered screen previews; forms are inert.
- `test-runtime/evidence-0.3.3/`: current test results. The older `evidence-0.3.2/`, `evidence/`, and Fames handoff files are historical.

Keep the plugin and `test-runtime/` side by side. Follow `test-runtime/DEVELOPER-TESTS.md` for pinned dependencies and disposable fixtures. The new screen fixture runs with `node run-wp-tests.mjs brand-preview-tests.php`. Use `python3 package-0.3.3.py --output /path/to/release` for packaging. Older package/build scripts remain historical helpers, not the current release command.

The main container/browser URL policy prevented rendered visual QA. Review desktop/mobile layout on staging and test both actual OAuth clients. If upgrading from a version before 0.3.2, refresh and resubmit pending page-design requests after that earlier Canvas label update.

`SOURCE-MANIFEST.json` records every source member except itself and the separate install ZIP. Dependencies, database/runtime copies, credentials, and transient logs are excluded. No GitHub push or live deployment occurred.
