# Connecting clients

Forge MCP is a remote MCP server over streamable HTTP. Any client that supports remote HTTP MCP servers with a custom header can connect.

- **Endpoint:** `https://your-host/mcp`
- **Authentication:** `Authorization: Bearer <token>`, where `<token>` is an access token from the dashboard's **Access tokens** page.

The endpoint accepts bearer tokens only. Being logged in to the dashboard in a browser does not grant MCP access.

## Create a token

1. Open **Access tokens** in the dashboard.
2. Enter a name that says where the token will live (for example `Laptop - Claude Code` or `CI - read only`).
3. Optionally pick an existing token under **Copy permissions from** to start with the same permissions. Otherwise the token starts read-only.
4. Choose **Create token** and copy the token. It is shown once; if you lose it, revoke it and create a new one.

The page also shows ready-made snippets with your URL (from `APP_URL`) and, right after creation, the new token filled in.

## Claude Code

```bash
claude mcp add --transport http forge-mcp https://your-host/mcp --header "Authorization: Bearer <token>"
```

Run `claude mcp list` to check that the server is connected, then ask Claude to list your Forge servers. Use `claude mcp remove forge-mcp` to remove it.

To share the server with a team through the repository, put it in the project's `.mcp.json` with the URL and token taken from environment variables, and optionally add default organization, server and site headers. The [Claude Code plugin](claude-code-plugin.md) writes this file for you with `/forge-mcp:configure`, and [Project defaults](project-defaults.md) explains the headers. Write the `X-Forge-*` header values as literals: an unset `${...}` variable becomes an empty header, and every call then fails with `Invalid X-Forge-… header.`

## Cursor

Add the server to `.cursor/mcp.json` in a project, or to `~/.cursor/mcp.json` for all projects:

```json
{
  "mcpServers": {
    "forge-mcp": {
      "url": "https://your-host/mcp",
      "headers": {
        "Authorization": "Bearer <token>"
      }
    }
  }
}
```

## Other clients

Most clients accept a JSON config like the one the dashboard shows:

```json
{
  "mcpServers": {
    "forge-mcp": {
      "type": "http",
      "url": "https://your-host/mcp",
      "headers": {
        "Authorization": "Bearer <token>"
      }
    }
  }
}
```

The exact keys differ between clients (some call the type `streamable-http`, some leave it out). Check your client's documentation for remote MCP servers with headers.

## One token per agent or device

Create a separate token for each agent, machine or CI job:

- Each token has its own [permissions](permissions.md), so a CI job can stay read-only while your laptop can deploy staging.
- The audit log records the token name for every permission-checked call, so you can tell who did what.
- You can revoke one token without disturbing the others.
- Rate limiting (120 requests per minute) is per token.
- Each token can store its own [default organization, server and site](project-defaults.md#token-defaults).

## Rotating and revoking

- **Revoke:** on **Access tokens**, choose **Revoke** next to the token. Agents using it lose access immediately.
- **Rotate:** create a new token with **Copy permissions from** set to the old one, update the client, then revoke the old token.

Access tokens do not expire on their own; revoke the ones you no longer use. The token list shows when each token was last used.

## Troubleshooting 401 and other connection errors

| Symptom | Likely cause | Fix |
|---|---|---|
| HTTP 401 `Unauthenticated.` | The `Authorization` header is missing or malformed, the token was revoked, or it was copied incompletely. | Check the header is exactly `Authorization: Bearer <token>`; create a new token if in doubt. |
| HTTP 401 only through a proxy or CDN | The proxy strips the `Authorization` header. | Configure the proxy to pass the header through. |
| HTTP 429 | More than 120 requests in a minute with this token. | Wait a minute; check the agent is not looping. |
| Redirects or HTML instead of JSON | The URL is wrong (missing `/mcp`) or HTTP is redirected to HTTPS and the client does not follow it. | Use the full `https://your-host/mcp` URL. |
| Connected, but no tools are listed | The token has no capabilities anywhere reachable, or no organization is exposed. | See [Tool not visible](troubleshooting.md#a-tool-is-not-visible-to-the-agent). |
| Every tool says `Forge is not connected.` | No Forge token is connected. | Connect Forge on the **Forge connection** page. |
| Every tool says `Invalid X-Forge-… header.` | An `X-Forge-*` header is empty or malformed, often an unset `${...}` variable. | Use literal values; see [the empty header trap](project-defaults.md#the-empty-header-trap). |

More in [Troubleshooting](troubleshooting.md).
