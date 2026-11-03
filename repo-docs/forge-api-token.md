# Forge API token

Forge MCP talks to Forge with one Forge API token that you create in Forge and paste into the dashboard. That token is the ceiling: an access token permission only works if the Forge token also carries the matching scope. Give the Forge token only the scopes for the capabilities you plan to grant.

## Creating the token

1. In Forge, open your account's API token settings and create a new token. Give it a recognisable name, such as `forge-mcp`.
2. Select the scopes you need from the tables below.
3. Copy the token and paste it into **Forge connection** in the Forge MCP dashboard. Forge MCP checks it against Forge, stores it encrypted, and shows only its last four characters from then on.

To change scopes later, create a new Forge token with the new scopes and use **Replace token** on the same page. Your exposed-organization selection is kept for the organizations the new token can see.

## Scopes per capability

| Capability | Tier | Default | Forge token scopes |
|---|---|---|---|
| `view` | read | allowed | `server:view` |
| `view_logs` | read | allowed | `server:manage-logs`, `site:manage-deploys`, `server:create-daemons` |
| `deploy` | action | off | `site:manage-deploys` |
| `restart_services` | action | off | `server:manage-services` |
| `manage_workers` | action | off | `server:create-daemons` |
| `view_env` | risky | off | `server:view` |
| `edit_env` | risky | off | `site:manage-environment` |
| `run_commands` | risky | off | `site:manage-commands` |
| `configure_workers` | risky | off | `server:create-daemons`, `server:delete-daemons` |
| `configure_deployments` | risky | off | `site:manage-deploys` |
| `reboot_server` | risky | off | `server:manage-services` |
| `maintenance_mode` | action | off | `site:manage-commands` |

The same information by scope (this is what the **Forge connection** page shows under **Required token scopes**):

| Forge scope | Needed by |
|---|---|
| `server:view` | `view`, `view_env` |
| `server:manage-logs` | `view_logs` |
| `site:manage-deploys` | `view_logs`, `deploy`, `configure_deployments` |
| `server:create-daemons` | `view_logs`, `manage_workers`, `configure_workers` |
| `server:manage-services` | `restart_services`, `reboot_server` |
| `site:manage-environment` | `edit_env` |
| `site:manage-commands` | `run_commands`, `maintenance_mode` |
| `server:delete-daemons` | `configure_workers` |

Forge MCP needs no scopes beyond these. Always include `server:view`: besides `view`, secret scrubbing reads each site's `.env` with it. Without it, tool output carries a `Warning: Secret scrubbing limited: …` line and only pattern-based scrubbing applies.

## Minimum-scope recipes

| Goal | Capabilities you will grant | Forge token scopes |
|---|---|---|
| Read-only (the default for new access tokens) | `view`, `view_logs` | `server:view`, `server:manage-logs`, `site:manage-deploys`, `server:create-daemons` |
| Deploy and restarts | read-only plus `deploy`, `manage_workers`, `restart_services` | the read-only scopes plus `server:manage-services` |
| Everything | all twelve capabilities | `server:view`, `server:manage-logs`, `site:manage-deploys`, `server:create-daemons`, `server:manage-services`, `site:manage-environment`, `site:manage-commands`, `server:delete-daemons` |

One Forge token serves every access token, so pick the scopes for the most powerful access token you intend to create. Per-agent limits are set with [permissions](permissions.md), not with separate Forge tokens.

## When a scope is missing

If a capability is granted to an access token but the Forge token lacks the scope, Forge refuses the request and the agent gets:

```text
Forge denied <action> (403). The Forge API token may be missing a required scope.
```

The call is recorded in the audit log as `error`. Fix it by creating a Forge token with the missing scope and replacing the token on the **Forge connection** page.

If Forge rejects the token itself (revoked or expired), every tool returns `Forge rejected the API token (401). Reconnect in the Forge MCP dashboard.`, and the dashboard home and **Forge connection** page show the error until a call succeeds again or you replace the token.
