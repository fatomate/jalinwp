# Security Audit — Initial JalinWP 0.3.3

Date: 6 September 2026. Scope: the uncommitted 0.3.3 candidate derived from `88b80bc9035cd146fc66d9cadabb785d550cfb3f`. Final bytes are identified by the release tag and archive manifests when those are published. This is a bounded static source review plus disposable-test record, not a security certification and not a claim that high or critical issues are absent from unreviewed environments.

## Review Method And Limits

Two independent read-only reviews covered authentication/OAuth/policy and mutation/content/filesystem surfaces. A third read-only review traced the discovery publication fix and named missing AJAX/page-render regressions, which were added in tests only. Reviewers inspected source; they did not run exploits or live sites. Parent and implementation workers executed the disposable suites listed in [Validation](VALIDATION.md).

Reviewer file readers redacted at least `class-oauth.php:480` and `class-settings.php:53`/`54` (secret-scanner). Findings below do not depend on those exact expressions. Token-generator entropy is therefore not certified by line-level inspection; lifecycle tests still exercise hashed storage and compare-and-swap, not cryptographic quality.

Coverage included route-limited bearer authentication, Application Password attribution, HTTPS/origin checks, callback allowlists, PKCE, one-use consent/code/refresh handling, exact resource binding, hashed credentials, revocation epochs, effective capabilities/consent, admin nonces, frozen reviewed changes, single execution claims, audit failure handling, typed WP/WC dispatch, page-scoped Gutenberg serialization, preview isolation, financial data boundaries, discovery path/ownership checks and packaging exposure. Analytics, finance and page-design internals were checked at auth/capability boundaries, not as full logic audits. No runtime proof of exploitability, proxy/CDN configuration, host `.well-known` behaviour, or browser consent testing is claimed.

## Required Finding — Fixed

**Medium: settings-page GET could publish discovery files without an action nonce.** An off-site navigation could make an authenticated administrator trigger server-derived metadata writes while gateway and OAuth were enabled. Paths and content were constrained (server-derived JSON, allowlisted segments, symlink rejection, ownership hashes). This was a CSRF-reachable state change, not attacker-controlled file content or path traversal.

The `admin_init` publication hook and its bootstrap call were removed. Publication now occurs after successful nonce/capability/POST-protected account setup, or through the protected Check Connection POST/AJAX actions. Stale or failed setup never publishes. The shared publisher still requires `manage_options`, enabled gateway/OAuth, confirmed webroot, path confinement, ownership hashes, a bounded option lock and persistence-failure recovery.

An independent review confirmed the hook is gone and that remaining writers are `quick_connect` and `diagnostics()` (POST and AJAX). Current admin discovery coverage passes **11/11**, including actual `FG_Admin::page()` GET with no files or ownership options, enabled-state AJAX wrong-method/unauthorized/bad-nonce rejection without writes, and successful nonce POST/AJAX canonical publication using synthetic HTTP mocks. Existing filesystem publisher tests pass **41/41**. Publication failures remain visible through Check Connection; quick setup does not certify a remote connection. Upgrades no longer self-publish.

## Other Findings — Documented Limits

These are low or informational dispositions. They were not treated as required medium defects and were not “fixed” by documentation:

- **Pre-auth, per-IP and per-user limits depend on the host.** Authenticated MCP requests share a per-user 60/minute bucket. Invalid bearer attempts are not charged to that bucket. OAuth registration/token/consent/revoke buckets key on `REMOTE_ADDR`; a proxy that does not present the real client address collapses or rotates those buckets. Registration and token endpoints also have global caps; revoke is per-IP only. Strong random tokens make guessing impractical; they do not provide denial-of-service protection. Host/CDN limits remain necessary.
- **Mapped financial metadata is administrator-trusted.** Key-name denial patterns are advisory. A configured scalar order-meta key can be read by an authorized sensitive-data connection. Administrators must not map credential fields. Configuration still requires `manage_options` plus `manage_woocommerce`; reads still require sensitive access and native order permissions.
- **Admin site inventory is included in basic read.** `wp_site_inspect` is a `manage_options` read tool and is not additionally marked sensitive, so ordinary WordPress consent on an administrator account can list plugin/theme names and versions.
- **Application Passwords use current site policy, not OAuth consent.** An Application Password for an enabled account receives the account’s current writes/sensitive/YOLO settings and WordPress capabilities. There is no frozen per-connection consent ceiling. Treat the password as a privileged credential.
- **Public discovery metadata is non-secret.** Documents contain endpoint URLs and capabilities, not credentials or account data. Wildcard CORS on those documents is expected. Metadata can remain readable after disable/removal; authorization still fails when the gateway is disabled. Owned files are not automatically deleted on configuration changes.
- **In-flight read-request policy is a snapshot.** Some read-tool checks use cached settings after authentication already captured a fresh grant snapshot. Revocation and current policy are enforced at authentication and write-execution boundaries. This does not promise atomic cancellation of an already-running read.
- **Filesystem and third-party plugin race limits.** Ownership records and atomic rename prevent ordinary conflicts. Concurrent writers with independent filesystem access, long filesystem stalls, initial exclusive-create partial reads, and external hooks that `COMMIT` during page-design transactions are outside the guarantees established here.
- **Numeric WooCommerce IDs.** Some read/delete paths interpolate schema-validated IDs without an extra integer cast. Noncanonical numeric strings can produce a route error. No path injection was identified; extra normalization was deferred.

No reachable critical or high issue was identified in the paths those reviews read. That is a scoped “no findings” statement, not a blanket certification and not proof of absence outside that scope.

## Dependencies, Secrets And Artifacts

The install ZIP contains no npm dependencies or development harness. Development-only `qs` **6.16.0** overrides in the test-runtime lockfile cleared four prior moderate `npm audit` findings without downgrading Playground or using `npm audit fix --force`. Parent independently reran `npm audit` in all three dependency trees (root tooling, WordPress runtime, DOM runtime), with zero findings. This is a development-tooling claim, not a plugin-runtime SBOM.

Redacted Betterleaks checks found no leaks in scanned plugin/bridge source and candidate archives. The commit hook remains enabled; no bypass is authorized. Source packaging excludes credentials, databases, downloaded dependencies, disposable sites, generated previews and local Pi state.

See [Release Notes](RELEASE-0.3.3.md) for installation intent and [Validation](VALIDATION.md) for executed counts and unverified environments. No live site was accessed or deployed.
