# Create The JalinWP GitHub Repository

Extract the repository kit. The resulting `jalinwp/` folder is the repository root: it directly contains `README.md`, `LICENSE`, `.gitignore`, `AGENTS.md`, `fames-mcp-gateway/`, `test-runtime/` and `scripts/`.

This kit contains source files, not a Git history. It does not create a GitHub repository, choose an owner, publish files or configure repository settings.

## Option A: GitHub Desktop

1. Open GitHub Desktop and sign in to your GitHub account.
2. Choose **File → Add Local Repository** and select the extracted `jalinwp/` folder.
3. If GitHub Desktop says that the folder is not a Git repository, use its option to create a repository there. Confirm the final repository location is the extracted `jalinwp/` folder, not another nested `jalinwp/jalinwp/` directory.
4. Keep the supplied README, license and `.gitignore`; do not generate replacements.
5. Review the changed files. Dependencies, temporary runtime data and `dist/` outputs should be ignored. Include the source, lockfiles, documentation and maintained tests.
6. Commit with a message such as `Initial JalinWP 0.3.3 source import`.
7. Choose **Publish Repository**, set the name to `jalinwp`, and select the intended personal or organization account. Choose its visibility deliberately, then publish.

If the Desktop creation dialog would create a different folder, use the commands below to initialize the extracted directory first, then add it as an existing local repository in Desktop.

## Option B: Git Commands

Open a terminal in the extracted `jalinwp/` folder:

```sh
git init -b main
git status --short
git add .
git diff --cached --stat
git commit -m "Initial JalinWP 0.3.3 source import"
```

Git may ask you to configure your author name and email if you have not done so on this computer. Use your chosen public author identity or GitHub-provided no-reply email.

Create an **empty** repository named `jalinwp` on GitHub under the intended owner. Leave GitHub's README, `.gitignore` and license initialization options off because this kit provides them.

Copy the remote URL GitHub shows. In the following command, replace `YOUR-OWNER` with your actual account or organization; it is a placeholder, not an existing project URL:

```sh
git remote add origin https://github.com/YOUR-OWNER/jalinwp.git
git push -u origin main
```

Authenticate using GitHub's supported sign-in method for your Git client. Do not insert an access token into the remote URL or a tracked file.

## Build The WordPress Release Attachment

Run from the repository root with Python 3.9+:

```sh
python3 scripts/check-repository.py
python3 scripts/test-package.py
python3 scripts/package.py
```

The current version produces:

- `dist/jalinwp-0.3.3.zip` — install this in WordPress.
- `dist/jalinwp-0.3.3-source.zip` — full development source.

Build outputs are ignored by Git. On GitHub, draft a release with tag `v0.3.3` and title `JalinWP 0.3.3`, targeting the intended commit. Attach the installable ZIP and, optionally, the complete source ZIP. Include a short description of the release and relevant validation limits. Publish when ready.

GitHub automatically generates source archives for a tag. Those archives contain the development repository, so distinguish them from the `jalinwp-0.3.3.zip` WordPress attachment.

## Repository Settings To Complete

- Add the description: **An open-source MCP gateway for WordPress and WooCommerce.**
- Use topics such as `wordpress`, `woocommerce`, `mcp`, `oauth` and `wordpress-plugin` if useful.
- Enable private vulnerability reporting or publish a private reporting channel, then keep `SECURITY.md` accurate.
- Review the included GitHub workflow before enabling it. It runs repository/package checks and Node bridge tests without live WordPress credentials. It does not run the WordPress integration suites; those remain documented local checks. GitHub Actions has not run merely because the kit contains a workflow.
- Add a real website, support link or maintainer contact only when you have chosen one. This kit intentionally supplies no invented contact information.

## Preserve Upgrade Compatibility

The repository can be named **jalinwp**, but the installed plugin remains at `fames-mcp-gateway/fames-mcp-gateway.php`. The existing `fames-mcp/v1` routes and stored identifiers are intentional. Renaming them just to match the repository could disrupt active installations and connections.

For ongoing work, read [Contributing](../CONTRIBUTING.md), [Agent Guidance](../AGENTS.md), and [Developer Tests](../test-runtime/DEVELOPER-TESTS.md). The included integration evidence documents earlier plugin development; repository initialization alone does not rerun those tests or deploy the plugin.
