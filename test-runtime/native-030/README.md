# Native Database Release Gate for v0.3.0

This harness runs WordPress and the plugin against **native PHP with mysqli and a local MySQL or MariaDB server**. It complements the existing WordPress Playground/SQLite suites. It does not connect to a staging or production WordPress site.

## Current Status

**Not Executed Against MySQL or MariaDB in This Workspace.** Native `php`, `mysql`, `mariadb`, `mysqld`, `mariadbd`, Docker, and Podman are unavailable. An ordinary `apt-get update` returned exit 100 before fetching packages:

```text
setgroups 65534 failed - setgroups (1: Operation not permitted)
setegid 65534 failed - setegid (22: Invalid argument)
seteuid 42 failed - seteuid (22: Invalid argument)
Method http has died unexpectedly
```

No package-manager sandbox override or privilege escalation was attempted. Both PHP files passed PHP syntax checking using the existing PHP WASM interpreter. The runner's prerequisite guard was checked and rejects Emscripten/PHP WASM. These checks are **not native database test results**.

## Prerequisites

- Native PHP CLI 8.1+ with `mysqli` and the extensions required by WordPress.
- A local development MySQL 8.x or MariaDB 10.6+ server accessible through `127.0.0.1` or a Unix socket.
- A local database account allowed to create/drop disposable databases and their tables. Do not provide credentials for a live database server or a local tunnel to one.
- An unpacked WordPress core directory and the plugin source directory. Only core PHP files, `wp-admin`, and `wp-includes` are copied. Existing `wp-config.php`, `wp-content`, object caches, MU plugins, and SQLite drop-ins are excluded.

## Run

From the development kit root (the directory containing `test-runtime` and `fames-mcp-gateway`):

```bash
export FG_NATIVE_WP_CORE_DIR="/absolute/path/to/unpacked/wordpress"
export FG_NATIVE_PLUGIN_DIR="$PWD/fames-mcp-gateway"
export FG_NATIVE_DB_USER="your_local_test_user"
export FG_NATIVE_DB_PORT="3306"
read -r -s -p 'Local Test Database Password: ' FG_NATIVE_DB_PASSWORD
export FG_NATIVE_DB_PASSWORD
php test-runtime/native-030/run.php > native-mysql-030-result.json
```

For a Unix socket, set `FG_NATIVE_DB_SOCKET` to an existing absolute socket path. No remote host setting is supported. Do not set a database name: the runner generates one with the prefix `fg_native_030_` and a random suffix, and never selects an existing database.

Run again against a local MariaDB server, saving its output as `native-mariadb-030-result.json`. Check the process exit code and JSON: a pass requires `status: "passed"`, all 14 cases passing, and `disposable_database_removed: true`. The JSON also records the actual PHP and database versions. WordPress core must be downloaded/unpacked beforehand; this runner makes no HTTP requests.

The runner copies files into a fresh private temporary directory, installs WordPress at the inert URL `https://fixture.invalid`, creates only synthetic users/grants/tokens/audit rows, executes the checks, and drops its generated database on completion. A database cleanup failure makes the process fail and identifies only the generated disposable database to remove manually. The cleanup routine will not remove an arbitrary existing directory.

## What the Gate Tests

1. Fresh native `dbDelta` creation, required columns/indexes, and version markers.
2. A legacy schema shape containing retained grants, tokens, activity, and a pending change.
3. Additive upgrade of audit, lifecycle, and server-context schema.
4. Preservation of access/finance settings, OAuth epoch, registration secrets, and token hashes.
5. Preservation of legacy grant fields without inventing lifecycle timestamps.
6. Preservation of activity history without inventing historical client attribution.
7. Revocation of old pending changes without trustworthy server context.
8. The repaired connection-list SQL against real MySQL/MariaDB.
9. Authentication of a retained bearer token after migration and its lifecycle transition.
10. Explicit connection-list error state when a joined table is temporarily unavailable.
11. Storage of authenticated OAuth client attribution in activity rows.
12. Idempotent repeated migration.
13. Core marker withholding when an additive column migration fails, then successful retry.
14. OAuth marker withholding when a lifecycle column migration fails, then successful retry.

The two failure tests intercept only their own fixture's expected ALTER statement to produce a real failing SQL statement. They check that the plugin observes the resulting missing column and does not advance the schema marker. The interception is always removed before retry. The missing-table connection test temporarily renames only the generated fixture's client table and restores it in `finally`.

## Limits

These tests do not replace browser UI checks, real ChatGPT/Claude connections, PHP-FPM/web-server integration, Kadence or block-theme rendering, Gutenberg editor validation, WooCommerce/HPOS tests, or external payment/affiliate integration checks. Passing one database family does not establish a pass for the other.

## Maintenance

- `run.php`: prerequisites, local-only database connection, isolated WordPress installation, copying, cleanup, and JSON result.
- `tests.php`: SQL/schema assertions, legacy fixture, lifecycle/list/error checks, and migration failure/retry assertions.
- If plugin schema or lifecycle names change, update the corresponding expected values here and rerun both native database families. Never relax the disposable database/driver guards to run these tests on an existing site.
