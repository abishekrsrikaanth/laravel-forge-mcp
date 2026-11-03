# Migrating from bretterer/forge-mcp-server

If you used [bretterer/forge-mcp-server](https://github.com/bretterer/forge-mcp-server), most tools carry over by name. This page lists what changed and how to switch.

## Tool names

| bretterer/forge-mcp-server | Forge MCP |
|---|---|
| `get_deployments` | `list_deployments` |
| `toggle_quick_deploy` | `set_quick_deploy`, which takes the desired state explicitly: `enabled: true` or `enabled: false` |
| `get_server_load` | Not available: Forge's current API has no server-load endpoint |
| `list_servers` | `list_servers` |
| `get_server` | `get_server` |
| `list_sites` | `list_sites` |
| `get_site` | `get_site` |
| `deploy_site` | `deploy_site` |
| `get_deployment_script` | `get_deployment_script` |
| `update_deployment_script` | `update_deployment_script` |
| `reboot_server` | `reboot_server` |
| `reset_deployment_state` | `reset_deployment_state` |

Forge MCP also adds worker management, `.env` editing, site commands, scheduled jobs, server events, service restarts and maintenance mode, among others, each behind a [capability](permissions.md). See the [tool reference](tools.md).

In Forge MCP, tools work inside a Forge **organization** and take an optional `organization` argument; with a single exposed organization you can leave it out. Tools that act on a site take both `server_id` and `site_id`.

## Differences

- **Self-hosted HTTP server instead of a local stdio process.** bretterer/forge-mcp-server runs on each developer machine via `npx` over stdio. Forge MCP is one Laravel app on your own server, reached over HTTPS at `/mcp` with a bearer token.
- **One Forge token, stored on your server.** The Forge API token is pasted once into the dashboard and stored encrypted. Agents and developer machines only get Forge MCP access tokens, which can be revoked individually.
- **Per-token permissions.** Each access token has its own capabilities, read-only by default, with overrides per organization, server and site. Tools a token cannot use are not listed.
- **Secret scrubbing.** Every tool result is scrubbed of known `.env` values and common token formats; only `get_site_env`, behind its own `view_env` capability, returns raw values.
- **Audit log.** Every permission-checked tool call (allowed, denied or failed) is recorded in the dashboard.

## Switching

1. [Install Forge MCP](installation.md) and complete `/setup`.
2. Connect it to Forge with a Forge API token. You can reuse the scopes of the token you used before, or create a narrower one ([Forge API token](forge-api-token.md)).
3. Expose the organizations your agents should reach on the **Forge connection** page.
4. Create an access token per agent or machine and grant the capabilities your workflows need. A token that only used the old read tools needs nothing beyond the defaults; `deploy_site` and `reset_deployment_state` need `deploy`; `update_deployment_script` and `set_quick_deploy` need `configure_deployments`; `reboot_server` needs `reboot_server`.
5. Remove the old server from your client and add Forge MCP ([Connecting clients](connecting-clients.md)). For Claude Code:

   ```bash
   claude mcp remove <old-server-name>
   claude mcp add --transport http forge https://your-host/mcp --header "Authorization: Bearer <token>"
   ```

6. Update any saved prompts or scripts that call `get_deployments` or `toggle_quick_deploy`.
7. Delete the Forge token the old server used if nothing else needs it.
