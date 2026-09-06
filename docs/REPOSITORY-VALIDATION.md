# Repository Kit Validation

Assembled on 6 September 2026 from the previously delivered JalinWP 0.3.3 full source archive. This is a repository-preparation change; the installable plugin stays at version 0.3.3.

## What Was Added

Root README, the existing GPL license, AGENTS.md, contributor/security guidance, changelog, Git/editor metadata, issue and pull-request templates, repository setup instructions, and standard-library Python packaging/verification scripts. The original source manifest is archived under `docs/history/`; generated source ZIPs receive a fresh manifest.

## Verification Performed

- Repository checker: required metadata, matching license copies, version agreement, legacy install layout, brand assets, curated source membership, and relative Markdown file targets.
- Packaging tests: 6/6 passed, including required metadata/assets, install/source equality, temporary/dependency/credential/database exclusions, retention of archived evidence transcripts, deterministic custom output, version mismatch, invalid paths, symlinks, and unsafe output locations.
- Local Node bridge regression: 16/16 passed.
- Git staging in a temporary copy: all maintained source files included; disposable runtime, dependency, environment, and build probes ignored.
- Fresh checkout built using only files staged by Git; both ZIPs passed integrity and manifest checks.
- Rebuilt installer compared to the previously delivered v0.3.3 installer: the same 54 member paths and identical file contents. Archive metadata is now deterministic, so the ZIP file hash itself can differ.
- CI YAML parsed and checked against the documented commands. The workflow has read-only repository permissions and no publish or deploy step.
- Known private-key/API-token pattern scan: no matches. This is a limited pattern check, not a comprehensive secret or security audit.

## Limits

GitHub Actions has not run and no GitHub repository was created or published by this task. No live site, OAuth provider account, or host configuration was changed.

The plugin integration evidence remains the prior 0.3.3 evidence because plugin runtime files were not modified. Those suites were not rerun merely to assemble this kit. Native MySQL/MariaDB, actual hosted-client reconnection, and desktop/mobile browser acceptance remain subject to the limits recorded in [plugin validation](../docs/VALIDATION.md).

Packaging requires Python 3.9+ and no third-party Python modules. The supplied CI workflow uses the official [checkout](https://github.com/actions/checkout), [setup-python](https://github.com/actions/setup-python), and [setup-node](https://github.com/actions/setup-node) actions. Their published usage was checked when assembling the workflow; its hosted execution must be confirmed after the repository is pushed.
