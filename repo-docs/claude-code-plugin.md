# Claude Code plugin

The `forge-mcp` plugin for [Claude Code](https://code.claude.com) adds two commands on top of the Forge MCP server:

| Command | What it does |
|---|---|
| `/forge-mcp:configure [site name or id]` | Connects the project to Forge MCP if needed, then lets you pick a default organization, server and site and saves them as [project defaults](project-defaults.md) or on your access token. |
| `/forge-mcp:diagnose [site id]` | Read-only diagnosis of the latest deployment of a site (the default site when no id is given): status, recent deployments, the failed deployment's log and the application log, summarised with a proposed fix. |

The plugin contains only these commands. It does not bundle the MCP server: the server is configured per project in `.mcp.json` under the name `forge-mcp`, so its tools are called `mcp__forge-mcp__<tool>` and the prompt is `/mcp__forge-mcp__diagnose_failed_deploy`.

## Install

In Claude Code:

```text
/plugin marketplace add abishekrsrikaanth/laravel-forge-mcp
/plugin install forge-mcp@laravel-forge-mcp
```

Recent versions of Claude Code also accept the one-step form `/plugin install forge-mcp --marketplace abishekrsrikaanth/laravel-forge-mcp`. The marketplace is this repository (`.claude-plugin/marketplace.json`, with the plugin in `plugins/forge-mcp`). To update later, run `/plugin marketplace update laravel-forge-mcp`.

## Environment variables

The project configuration written by `/forge-mcp:configure` reads two environment variables, so that no URL or token ends up in the repository:

| Variable | Value |
|---|---|
| `FORGE_MCP_URL` | The base URL of your Forge MCP installation, without `/mcp`, for example `https://forge-mcp.example.com`. |
| `FORGE_MCP_TOKEN` | An access token from the dashboard's **Access tokens** page. Use one token per developer or machine. |

Export them in the shell you start Claude Code from (for example in your shell profile):

```bash
export FORGE_MCP_URL=https://forge-mcp.example.com
export FORGE_MCP_TOKEN=...
```

Claude Code reads environment variables when it starts, so restart it after setting them.

## `/forge-mcp:configure`

1. **Connection.** If the `forge-mcp` tools are not available, the command adds a `forge-mcp` entry to the project's `.mcp.json` (merging it with any servers already there) and stops:

   ```json
   {
     "mcpServers": {
       "forge-mcp": {
         "type": "http",
         "url": "${FORGE_MCP_URL}/mcp",
         "headers": {
           "Authorization": "Bearer ${FORGE_MCP_TOKEN}"
         }
       }
     }
   }
   ```

   Set the environment variables, restart Claude Code, approve the new project server when asked, and run `/forge-mcp:configure` again.
2. **Choice.** It lists your organizations, servers and sites and lets you pick by name. A site name or id after the command (`/forge-mcp:configure shop.example.com`) pre-selects that site.
3. **Where to save.**
   - **This project** (the default) adds `X-Forge-Organization`, `X-Forge-Server` and `X-Forge-Site` headers with literal values to the `forge-mcp` entry in `.mcp.json`. The `Authorization` header is left as it is, and no token is ever written to the file. Commit `.mcp.json` to share the defaults with your team.
   - **This access token** calls `set_defaults`, so the defaults follow your token into every project that sends no `X-Forge-*` headers.
4. **Verify.** For project headers, open `/mcp`, select `forge-mcp` and choose Reconnect (or run `/mcp reconnect all`), then the command checks the result with `get_defaults`.

See [Project defaults](project-defaults.md) for precedence and the rules that apply to defaults.

## `/forge-mcp:diagnose`

```text
/forge-mcp:diagnose          # the default site
/forge-mcp:diagnose 456      # site 456
```

The command calls `get_defaults` to find the site, then `get_deployment_status`, `list_deployments` (the last 3), `get_deployment_log` for the latest failed deployment and `get_site_log` (application log, last 100 lines). It summarises the most likely cause in a few bullets, quotes the decisive log lines and proposes a fix. These are the same steps as the [`diagnose_failed_deploy`](tools.md#prompt-diagnose_failed_deploy) prompt.

It is read-only: the command may only use read tools, and it never deploys or changes anything. The access token needs `view` and `view_logs` on the site.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `/forge-mcp:configure` keeps saying the server is not available | The environment variables are not set in the shell Claude Code was started from, the project server was not approved, or the URL is wrong. | Check `echo $FORGE_MCP_URL`, restart Claude Code from that shell, and open `/mcp` to approve or reconnect `forge-mcp`. |
| `forge-mcp` fails to connect with HTTP 401 | `FORGE_MCP_TOKEN` is unset or wrong, so the client sends `Bearer ` with no token. | Set the variable to a valid access token and restart Claude Code. |
| Every call answers `Invalid X-Forge-… header.` | An `X-Forge-*` header is empty or malformed, usually because it uses a `${...}` variable that is unset. | Use literal values in `.mcp.json` (see [the empty header trap](project-defaults.md#the-empty-header-trap)), then reconnect. |
| `X-Forge-Site requires X-Forge-Server.` | The site header is set without the server header. | Add `X-Forge-Server`, or remove `X-Forge-Site`. |
| The new defaults are not used | The client has not reconnected, or the server is also configured in your user or local scope (for example with `claude mcp add`), which takes precedence over the project's `.mcp.json`. | Reconnect in `/mcp`. Run `claude mcp list` to see where `forge-mcp` comes from, and `claude mcp remove forge-mcp` to remove a local entry. |
| `get_defaults` shows token defaults as overridden | The project sends `X-Forge-*` headers, which replace the token's defaults entirely. | Expected. Remove the headers to use the token defaults. |
| The commands are missing | The plugin is not installed or is disabled. | Run `/plugin` and check that `forge-mcp` is installed and enabled. |
| `/forge-mcp:diagnose` reports a missing permission | The token lacks `view` or `view_logs` on the site. | Enable it for the token in the dashboard ([Permissions](permissions.md)). |

More in [Troubleshooting](troubleshooting.md) and [Connecting clients](connecting-clients.md).
