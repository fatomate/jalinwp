# Local MCP bridge

This dependency-free Node.js process connects a **local stdio MCP client** to your WordPress site's JalinWP. WordPress and WooCommerce tools run on the site; this process transports their JSON-RPC messages over authenticated HTTPS.

Requires **Node.js 22 or newer**, an activated/configured gateway plugin, a working HTTPS endpoint, and a WordPress **Application Password** for an authorized user. No `npm install` or build step is needed. Keep this folder on the computer running your MCP client.

## 1. Prepare your WordPress credentials

1. Activate the plugin and configure gateway access in its WordPress settings.
2. Create a dedicated WordPress user with only the roles needed for the intended tools, and grant that user gateway access in the plugin settings.
3. In that user's WordPress profile, create an Application Password named for your client, such as `JalinWP - Claude Desktop`. Use this generated password, not the user's normal login password.
4. Save it in a local plain-text file outside the plugin directory, project repositories, and shared folders. The file can contain the password with or without WordPress's display spaces and one trailing newline. On macOS/Linux, restrict the file to your account:

```bash
chmod 600 /absolute/private/path/wordpress-app-password
```

On Unix, the bridge requires that the file belongs to the account running Node and has no group/other permissions; modes `600` and `400` work. Symlinks are refused where the operating system supports `O_NOFOLLOW`. On Windows, restrict the file's access using Windows file permissions; the bridge does not validate Windows ACLs.

Use the final URL shown by your gateway settings, for example:

```text
https://shop.example/wp-json/fames-mcp/v1/mcp
```

WordPress subdirectory installations can include their subdirectory in this URL. The bridge refuses HTTP, URL credentials, query strings, fragments, and redirects. If your WordPress REST URL currently uses a `?rest_route=` query, configure pretty permalinks and use the `/wp-json/` endpoint first.

## 2. Configure your client

### Claude Desktop

Open **Settings → Developer → Edit Config**. Merge this entry into your existing `mcpServers` object, replacing all paths, the site URL, and the WordPress username:

```json
{
  "mcpServers": {
    "fames-wordpress": {
      "command": "/absolute/path/to/node",
      "args": ["/absolute/path/to/fames-mcp-gateway/bridge/stdio.mjs"],
      "env": {
        "FG_MCP_URL": "https://shop.example/wp-json/fames-mcp/v1/mcp",
        "FG_WP_USER": "your-mcp-wordpress-user",
        "FG_WP_APP_PASSWORD_FILE": "/absolute/private/path/wordpress-app-password"
      }
    }
  }
}
```

Use the executable path reported by `command -v node` on macOS/Linux or `where node` on Windows. In Windows JSON, escape backslashes, for example `C:\\Users\\you\\fames-mcp-gateway\\bridge\\stdio.mjs`. Fully quit and reopen Claude Desktop after saving. See the [official local MCP setup guide](https://modelcontextprotocol.io/docs/develop/connect-local-servers).

### Cursor

Use the same configuration in your user-level `~/.cursor/mcp.json`, adding `"type": "stdio"` inside the `fames-wordpress` object. User-level configuration keeps the private credential path out of project configuration. Enable the server from Cursor's MCP settings. See [Cursor's MCP configuration documentation](https://cursor.com/docs/mcp).

### Other clients and ChatGPT

Other clients that can launch a local stdio MCP process can use the same executable, script, and environment variables. Client configuration formats may differ.

**ChatGPT's web connector cannot run this local script.** Connecting this gateway through a ChatGPT remote connector requires a separate hosted, OAuth-capable gateway that securely maps authorized requests to WordPress credentials. That gateway and OAuth authorization server are **not included** in this package. This bridge does not claim direct ChatGPT-web compatibility.

## Environment variables

| Variable | Purpose |
| --- | --- |
| `FG_MCP_URL` | Required: final, full HTTPS MCP endpoint. |
| `FG_WP_USER` | Required: WordPress username. |
| `FG_WP_APP_PASSWORD_FILE` | Preferred: local Application Password file. Takes precedence if both password options are set. |
| `FG_WP_APP_PASSWORD` | Alternative: Application Password in the process environment. Prefer the file option to keep credentials out of client JSON. |

The bridge never prints credentials, request headers, remote HTTP error bodies, or raw network exceptions. Normal successful tool results are passed through to the client and may include authorized site data. Do not disable TLS verification; startup rejects `NODE_TLS_REJECT_UNAUTHORIZED=0`.

## Transport behavior and limits

- Each newline-delimited JSON-RPC message becomes one HTTPS POST with HTTP Basic authentication. Notifications and client responses require an empty `202 Accepted` acknowledgement and produce no stdout response.
- Requests run sequentially, including the `initialize` → `notifications/initialized` exchange. Request IDs and valid JSON-RPC results/errors are preserved.
- The `MCP-Protocol-Version` header starts at `2025-03-26` and then uses the negotiated initialization version. Supported versions are `2025-11-25`, `2025-06-18`, and `2025-03-26`.
- Input lines and response bodies are limited to **1 MiB** each. Each outbound request, including its response body, has a **30-second deadline**.
- The HTTP Accept header includes `application/json, text/event-stream`, as specified by MCP. This bridge is scoped to this plugin's stateless JSON responses: it does not implement SSE, background server notifications, session resumption, or a general-purpose remote MCP client.
- There are **no automatic retries**. A timeout or dropped connection during a write can occur after WordPress saves the change. Inspect the resource before deciding whether to submit it again.
- Transport failures return JSON-RPC error `-32098`; timeouts use `-32097`. Malformed input uses `-32700` or `-32600`. Notification failures are reported only on stderr.

The framing and HTTP lifecycle follow the relevant [MCP transport rules](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports).

## Check the installation

Ask your client to list the gateway tools and inspect your site before attempting writes. Use a staging site for the first content or product update. In plugin version 0.3.2, the bridge uses the site's selected Change Mode: Read Only, Reviewed Changes or YOLO Mode. YOLO writes execute in the original tool call without WordPress dashboard approval; Application Password connections have no extra OAuth consent step. Native permissions and data access still apply. Client-side confirmations may still appear. See [YOLO Mode](../docs/YOLO-MODE.md).

Common failures:

| Symptom | Check |
| --- | --- |
| Node executable not found | Use an absolute Node.js executable path; desktop apps may have a different PATH from your terminal. |
| HTTP 401/403 | Correct username and Application Password, plugin gateway access, and web-server forwarding of the Authorization header. |
| Redirect or HTTPS failure | Final site hostname/path, valid certificate, and CDN or security-plugin rules. |
| HTTP 404 | Plugin activation and the exact pretty-permalink REST endpoint. |
| Body size limit | Ask for fewer results or smaller content fields. |
| Timeout on a write | Inspect the target in WordPress before retrying. |

To revoke a client, revoke its Application Password in WordPress and remove its MCP configuration.

## Tests

The separate source kit includes the tests; they are excluded from the install ZIP. From the source kit's plugin directory:

```bash
node --test bridge/test.mjs
```

Tests use stub HTTPS transports and temporary credential files. They cover authentication placement, URL/permission validation, lifecycle ordering, version negotiation, notifications, transport errors, timeout/abort behavior, no retries, response bounds, UTF-8 framing, and credential-safe errors. They do **not** substitute for an integration test against a real WordPress/WooCommerce installation and your chosen desktop client.
