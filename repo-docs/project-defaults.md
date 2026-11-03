# Project defaults

Most Forge MCP tools work on one server or site, so an agent normally passes `server_id` and `site_id` on every call. If a repository always deploys to the same Forge site, you can configure a **default** organization, server and site once. Tools then fill in whatever the agent leaves out, and "show the last deploy log" just works.

Defaults come from one of two places:

| Source | Where it lives | Applies to | Set with |
|---|---|---|---|
| **Project headers** | `X-Forge-*` headers in the client's MCP config, usually the project's `.mcp.json` | Every call this client makes from this project, with any token | Editing `.mcp.json`, or [`/forge-mcp:configure`](claude-code-plugin.md) |
| **Token defaults** | Stored in Forge MCP, one set per access token | Every call made with that token, when no project header is sent | `set_defaults`, the token's **Permissions** page in the dashboard, or `/forge-mcp:configure` |

`get_defaults` shows which defaults are in effect and where they come from. `list_organizations` also prints them on its last line.

## Precedence

1. **Explicit arguments win.** An `organization`, `server_id` or `site_id` the agent passes is always used as given.
2. **Project headers replace token defaults as a bundle.** If any of `X-Forge-Organization`, `X-Forge-Server` or `X-Forge-Site` is sent, the token's stored defaults are ignored entirely, including values the headers leave out. The two sources are never merged.
3. **Token defaults** apply when no project header is sent.
4. **The single exposed organization** is used when no organization is given or defaulted.

Defaults are also scoped to each other:

- A default server applies only in its own organization. If the agent passes a different `organization`, the default server (and site) is not used.
- A default site applies only on its own server. If the agent passes a different `server_id`, it must pass `site_id` too.
- A default site without a default server is never used (`X-Forge-Site requires X-Forge-Server.` for headers).

Where `server_id` or `site_id` is only an optional filter, it is never filled in from defaults. This applies to `site_id` in `list_workers`, `list_scheduled_jobs`, `get_scheduled_job_output`, `restart_php_fpm` and `create_worker`, and to `server_id` in `list_sites`. For example, `list_sites` still lists every site in the organization when you have a default server.

When nothing supplies a required id, the tool answers `server_id is required (no default server is configured for this project or token).` or `site_id is required (no default site is configured for server 12).`

## Project headers

Add the headers to the server entry in your client's config. For Claude Code, the project's `.mcp.json`:

```json
{
  "mcpServers": {
    "forge-mcp": {
      "type": "http",
      "url": "${FORGE_MCP_URL}/mcp",
      "headers": {
        "Authorization": "Bearer ${FORGE_MCP_TOKEN}",
        "X-Forge-Organization": "acme",
        "X-Forge-Server": "123",
        "X-Forge-Site": "456"
      }
    }
  }
}
```

| Header | Value |
|---|---|
| `X-Forge-Organization` | An organization slug (letters, digits, `-` and `_`, up to 64 characters). It must be an exposed organization; otherwise every call that uses it is denied. |
| `X-Forge-Server` | A Forge server id (a positive whole number). |
| `X-Forge-Site` | A Forge site id on that server. Requires `X-Forge-Server`. |

All three are optional. Send only `X-Forge-Organization` to pick an organization, or the organization and server for a server-only default.

The file contains ids, not secrets, so it can be committed: everyone on the team gets the same defaults with their own token. Claude Code expands `${VAR}` and `${VAR:-default}` in `url` and `headers`, so the URL and token stay in each developer's environment (`FORGE_MCP_URL` and `FORGE_MCP_TOKEN`). Never write a literal token into `.mcp.json`.

### The empty header trap

Write `X-Forge-*` values as literals, not as `${...}` variables. If a variable is unset, Claude Code sends the header with an empty value. An empty or malformed header is not ignored: Forge MCP answers every tool call that works inside an organization (and `get_defaults`) with `Invalid X-Forge-Server header.` (or the name of the header concerned) until it is fixed. This applies even to calls that pass every id explicitly, because the headers are validated on each of those calls. Only `list_organizations` without an `organization` argument still works, and it then shows no defaults line. The header's value is never echoed back.

The same applies to `Authorization: Bearer ${FORGE_MCP_TOKEN}`: with the variable unset, the client sends `Bearer ` and the server answers HTTP 401.

After you change `.mcp.json`, reconnect the server so the new headers are sent: open `/mcp`, select `forge-mcp` and choose Reconnect (or run `/mcp reconnect all`).

### Other clients

Any client that lets you set custom headers on a remote MCP server can send the same headers. Clients that cannot set headers can use token defaults instead. Without an HTTP request there are no headers at all, so token defaults are the only source.

## Token defaults

Each access token can store one default organization, server and optional site:

- **From the agent:** `set_defaults` with `server_id` and optionally `organization` and `site_id`. Its arguments are used as given and never filled in from existing defaults. `clear_defaults` removes them.
- **From the dashboard:** the **Defaults** card on the token's **Permissions** page has the same fields, picked from your Forge servers and sites, with **Save defaults** and **Clear**.

Saving replaces the previous defaults. They are deleted together with the token. Token defaults suit a token used in one place (a CI job, a single project); for a token you use in several repositories, prefer project headers.

## Tools

| Tool | What it does |
|---|---|
| [`get_defaults`](tools.md#get_defaults) | Shows the defaults in effect for this call and their source. When project headers are in effect and the token also has stored defaults, it shows those as overridden. |
| [`set_defaults`](tools.md#set_defaults) | Stores defaults for the token in use. |
| [`clear_defaults`](tools.md#clear_defaults) | Removes the token's stored defaults. Headers cannot be removed this way; edit the client config instead. |

All three need the `view` capability somewhere. `set_defaults` needs `view` on the server, or on the site when one is given.

## Security notes

- **Defaults never bypass a check.** A defaulted id goes through exactly the same checks as an explicit one: the organization must be exposed, the server must be in it, the site must be on the server (`Site 34 is not on server 12.`), and the token needs the capability on that target. A denied site stays denied.
- **Headers are untrusted input.** Anyone who controls the client config controls the headers, but they only choose between targets the token can already reach. Malformed values are rejected rather than ignored, and never echoed.
- **Names are shown only for what the token can see.** `get_defaults` and `set_defaults` show a server or site name only when the token has `view` on it; otherwise they show the bare id.
- **Audited.** When a default fills in a value, the audit log entry's arguments include `_defaults: "header"` or `_defaults: "token"`, so you can tell an agent's explicit choice from a default.
