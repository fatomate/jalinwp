# Security

JalinWP exposes authorized WordPress and WooCommerce operations through MCP. Native account permissions, site access settings and OAuth consent determine the available access. Fresh installations are Read Only; enabling Reviewed Changes, YOLO Mode or sensitive-data access is an administrator choice.

## Report A Vulnerability Privately

If the GitHub repository has private vulnerability reporting enabled, open **Security → Report A Vulnerability**. Otherwise, use a private reporting channel explicitly published by the repository maintainer. This source kit does not configure that GitHub feature or establish a private contact address.

Do not publish exploitable details, credentials, OAuth tokens, personal data or customer/order exports in a public issue. A useful private report includes:

- Affected JalinWP version and relevant WordPress/PHP/WooCommerce versions.
- A concise description of the issue and its impact.
- Reproduction steps using synthetic data on a disposable site.
- Required account privileges and access mode.
- A minimal proof of concept or sanitized evidence.

Maintainers should enable GitHub private vulnerability reporting or publish another private reporting channel before inviting external vulnerability reports. No response-time commitment or supported-version maintenance policy has been established by this repository kit.

## Security-Relevant Behavior

- **Read Only** prevents changes through the gateway. **Reviewed Changes** requires WordPress approval before the requesting connection applies the change. **YOLO Mode** permits supported changes without that dashboard approval while retaining permission checks and activity logging.
- OAuth permissions are bounded by the original consent, current gateway settings and current WordPress permissions. Expanding site permissions does not silently expand an existing grant.
- Revoking a connection ends that grant's access. Deleting revoked/expired connection history also removes its stored tokens and codes. Activity Log records remain separate.
- Disabling the gateway blocks MCP access. An Application Password used by the optional local bridge remains a WordPress credential and should be revoked separately when it is no longer needed.
- HTTPS is required for remote authentication. A hosting firewall can block requests before WordPress receives them; PHP diagnostics cannot observe those requests.

See [OAuth Development](docs/OAUTH-DEVELOPMENT.md), [Change Modes](docs/YOLO-MODE.md), and [Connection Recovery](docs/CONNECTION-RECOVERY.md) for implementation and operational details.

## Development And Release Hygiene

Use the disposable test runtime with synthetic data. Keep real credentials, private keys, production database exports and customer data outside the repository and release archives. `.gitignore` helps avoid accidental staging; it does not remove information already committed.

If a real credential is exposed, revoke or rotate it at its issuer. Removing a file in a later commit does not invalidate the credential or remove earlier Git history.

The [validation report](docs/VALIDATION.md) records completed tests and their limits. It is not an independent security audit or a guarantee of compatibility with every host, database, theme or extension.
