# Temporary WordPress integration runtime

Installed npm packages: `@php-wasm/cli@3.1.52`, `@wp-playground/cli@3.1.52`.

## Syntax check

From this directory:

```sh
node node_modules/@php-wasm/cli/php-wasm.js -l ../fames-mcp-gateway/includes/class-core.php
```

The standalone CLI currently runs PHP 8.5.8. The WordPress runner pins PHP 8.3.32.

## Actual WordPress tests

```sh
node run-wp-tests.mjs runtime-smoke.php
node run-wp-tests.mjs wp-tools-tests.php
```

`run-wp-tests.mjs` accepts a PHP file under this directory. It copies the prepared WordPress fixture into a fresh isolated directory, runs the PHP file inside actual WordPress/PHP WASM, prints captured output and exception/fatal information, then removes the per-test runtime. It exits nonzero on failed or missing completion.

Fixture: WordPress 6.8.8, PHP 8.3.32, WordPress SQLite Integration (`WP_SQLite_DB`). WooCommerce 10.2.2 is present but inactive. This validates WordPress/Woo behavior but does not replace a production PHP-FPM/MySQL staging smoke test.

Paths inside PHP:

- `/wordpress/wp-load.php`: WordPress bootstrap.
- `/wordpress/wp-content/plugins/fames-mcp-gateway`: live plugin source mount.
- `/wordpress/wp-content/plugins/woocommerce`: WooCommerce fixture.
- `/test-results`: this directory; use it for output JSON or fixture scripts.

Tests must bootstrap WordPress/desired plugins themselves. They may set the current user to fixture administrator ID 1. No real site, credentials, orders, or payment gateway is involved.

`<test-stem>-output.json` captures status/stdout/errors. Tests should throw on failed assertions; they may also write dedicated JSON result files to `/test-results`.

## Fixture preparation

The WordPress archive was downloaded by Playground and extracted to `fixtures/wordpress`; SQLite installation was performed by `baseline-blueprint.json`. `woo-fixture-blueprint.json` added WooCommerce 10.2.2 without activation. Rerunning tests requires no WordPress download because the runner uses `install-from-existing-files-if-needed`.
