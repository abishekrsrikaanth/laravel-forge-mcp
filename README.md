# Forge MCP

Stop clicking through Forge mid-session. Forge MCP is a self-hosted Laravel app that lets AI coding agents (Claude Code, Cursor and any other MCP client) operate your [Laravel Forge](https://forge.laravel.com) servers and sites: check a deployment, read the logs, restart a queue worker, ship a fix. It stays locked down until you say otherwise.

You run it on your own Forge server, connect it to Forge with your own API token, and hand each agent a separate access token whose permissions you control.

**Documentation:** the full guide is in [repo-docs/](repo-docs/README.md): [getting started](repo-docs/getting-started.md), [installation](repo-docs/installation.md), [permissions](repo-docs/permissions.md), [security](repo-docs/security.md), [tool reference](repo-docs/tools.md), [project defaults](repo-docs/project-defaults.md), the [Claude Code plugin](repo-docs/claude-code-plugin.md) and [troubleshooting](repo-docs/troubleshooting.md).

## Features

- 41 MCP tools covering organizations, servers, sites, deployments, logs, queue workers and daemons, `.env` files, site commands, deployment scripts, scheduled jobs, server events, service restarts, maintenance mode and defaults.
- A `diagnose_failed_deploy` prompt for a guided, read-only post-mortem of the latest deployment.
- Project defaults: set a default organization, server and site per project (`X-Forge-*` headers in `.mcp.json`) or per access token, so agents can leave the ids out.
- A Claude Code plugin with `/forge-mcp:configure` and `/forge-mcp:diagnose`.
- Per-token permissions with 12 independent capabilities, plus overrides per organization, server and site.
- Secret scrubbing of every tool result, and an audit log of every permission-checked tool call (allowed, denied or failed).
- A small web dashboard: first-run setup, Forge connection, access tokens with copy-paste client snippets, per-token permission editor, and an audit log viewer.
- Self-hosted and open source (MIT). Your Forge token is stored encrypted and never leaves your server.

## Safety model

- **Locked down by default.** A new token can only read: `view` and `view_logs`. Everything else is off until you enable it.
- **Per-token permissions.** Each token gets its own set of capabilities, grouped into read, action and risky tiers. Capabilities are independent: none implies another.
- **Overrides per organization, server and site.** Allow or deny a capability for one organization, server or site without changing the token as a whole. Tools a token cannot use are not listed to the agent.
- **Server-level only for restarts and reboot.** `restart_services` and `reboot_server` affect every site on a server, so they can never be granted for a single site.
- **Sites on a server share one Linux user.** Every site on a Forge server runs as the same `forge` user, so a risky capability granted on one site (running commands, editing `.env`, configuring workers) can affect the other sites on that server. Grant risky capabilities per server unless you trust the agent with every site on it. Restarts, reboots, workers that run as root and raw worker (supervisor) config always require the permission at server level; a site-level worker's directory must stay inside the site's root directory.
- **Secret scrubbing.** Known secrets (for example values from a site's `.env`) are replaced with `[REDACTED]` markers in tool output. Only `get_site_env`, behind its own `view_env` capability, returns raw values.
- **Audit log.** Every permission-checked tool call (allowed, denied or failed) is recorded with the token, tool, target and outcome. Long arguments are truncated (4,000 characters per value, 16 KB in total). Entries are pruned after 90 days (`FORGE_MCP_AUDIT_RETENTION_DAYS`) by a daily scheduled job.
- **Worker and site matching rule.** Forge does not say which site a queue worker belongs to, so it is inferred from the worker's directory and site roots, and an action on a worker is allowed only if every site it could belong to permits it (with no match, server-level rules apply).

## Requirements

- PHP 8.4 or newer, with Composer and Node.js 22 (to build assets)
- A Laravel Forge account and a Forge API token
- A database (SQLite, MySQL or Postgres)
- A scheduler (`php artisan schedule:run` every minute) so audit log pruning runs

## Install on Forge

1. In Forge, create a new site on a server and install it from this repository (`abishekrsrikaanth/laravel-forge-mcp`).
2. Set the environment variables for the site (`.env`):
   - `APP_ENV=production`, `APP_DEBUG=false`
   - `APP_KEY`: generate one with `php artisan key:generate --show`
   - `APP_URL`: the public https URL of the site
   - `DB_CONNECTION` and the matching `DB_*` variables (SQLite works for a single-user install)
3. Set the deployment script to:

   ```bash
   cd $FORGE_SITE_PATH
   git pull origin $FORGE_SITE_BRANCH

   $FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
   npm ci && npm run build

   ( flock -w 10 9 || exit 1
       echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

   $FORGE_PHP artisan migrate --force
   $FORGE_PHP artisan optimize
   ```

4. Enable the scheduler for the site (Forge: Scheduler, or add a cron entry running `php artisan schedule:run` every minute).
5. Enable SSL (Let's Encrypt) for the site. Always serve Forge MCP over HTTPS: access tokens travel in a header.
6. Deploy, then **immediately** visit `https://your-host/setup` to create the owner account. Setup is available once and disappears after the owner exists. Until then, anyone who can reach the app can claim it, so complete setup right after the first deploy (or restrict access to the site, e.g. by IP, until you have).
7. Open **Forge connection** in the dashboard and paste your Forge API token.

## Install elsewhere

```bash
git clone https://github.com/abishekrsrikaanth/laravel-forge-mcp.git
cd laravel-forge-mcp
composer install --no-dev
npm ci && npm run build
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # when using SQLite
php artisan migrate --force
```

Serve `public/` with PHP-FPM behind Nginx or Caddy over HTTPS, run `php artisan schedule:run` every minute, and visit `/setup` straight away: until the owner account exists, anyone who can reach the app can claim it.

## Creating a Forge API token

In Forge, create an API token and select only the scopes you need. The table shows the scopes each capability needs; a token permission only works if the Forge token also carries the matching scope. To enable everything, select all of the scopes listed below.

| Capability | Tier | Default | What it allows | Forge token scopes |
|---|---|---|---|---|
| `view` | read | allowed | Browse organizations, servers and sites, and check deployment status. | `server:view` |
| `view_logs` | read | allowed | Read site, deployment and worker logs. | `server:manage-logs`, `site:manage-deploys`, `server:create-daemons` |
| `deploy` | action | off | Trigger deployments. | `site:manage-deploys` |
| `restart_services` | action | off | Restart services such as PHP, Nginx and databases. | `server:manage-services` |
| `manage_workers` | action | off | Restart, start and stop existing queue workers. | `server:create-daemons` |
| `view_env` | risky | off | Read the .env file, including secrets. | `server:view` |
| `edit_env` | risky | off | Change the .env file. | `server:view`, `site:manage-environment` |
| `run_commands` | risky | off | Run arbitrary commands in a site directory. | `site:manage-commands` |
| `configure_workers` | risky | off | Create, change and delete background processes (runs arbitrary commands, optionally as root). | `server:create-daemons`, `server:delete-daemons` |
| `configure_deployments` | risky | off | Change the deployment script and quick deploy. | `site:manage-deploys` |
| `reboot_server` | risky | off | Reboot the whole server. | `server:manage-services` |
| `maintenance_mode` | action | off | Put a site into and out of maintenance mode. | `site:manage-commands` |

Forge MCP needs no scopes beyond these. For the read-only defaults (`view`, `view_logs`), `server:view`, `server:manage-logs`, `site:manage-deploys` and `server:create-daemons` are enough.

## Connecting a client

Create an access token in the dashboard (**Access tokens**), choose its permissions, and copy it once; it is shown a single time. The MCP endpoint is `https://your-host/mcp`.

### Claude Code

```bash
claude mcp add --transport http forge-mcp https://your-host/mcp --header "Authorization: Bearer <token>"
```

### Other clients

Any client that supports remote (streamable HTTP) MCP servers with custom headers works. A typical JSON config:

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

The exact keys differ between clients; the dashboard shows ready-made snippets for each token. Requests are rate limited to 120 per minute per token.

## Tool reference

Kind: `read-only` tools never change anything; `destructive` tools are annotated as destructive so clients can ask for confirmation; `write` tools change state without being destructive. A tool appears in the agent's tool list only when the token has its capability somewhere.

| Tool | Capability | Kind | What it does |
|---|---|---|---|
| `list_organizations` | `view` | read-only | List the Forge organizations this MCP server can access. |
| `list_servers` | `view` | read-only | List the Forge servers in an organization: id, name, IP address, PHP version and connection status. |
| `get_server` | `view` | read-only | Show details of one Forge server: name, IP address, provider, region, PHP and Ubuntu versions, database type, readiness. |
| `list_sites` | `view` | read-only | List Forge sites: id, name, server, PHP version and deployment status. |
| `get_site` | `view` | read-only | Show details of one Forge site: name, URL, repository, PHP version, deployment status, quick deploy and directories. |
| `get_deployment_status` | `view` | read-only | Show the current deployment status of a Forge site (idle when no deployment is running). |
| `list_deployments` | `view` | read-only | List recent deployments of a Forge site: id, status, start time, commit hash and commit message. |
| `get_deployment_log` | `view_logs` | read-only | Read the output log of one deployment of a Forge site (last lines only). |
| `deploy_site` | `deploy` | write | Deploy a Forge site and wait briefly for the result. |
| `get_site_log` | `view_logs` | read-only | Read the application, nginx access or nginx error log of a Forge site (last lines only). |
| `list_workers` | `view` | read-only | List the background processes (queue workers, daemons) on a Forge server: id, status, process count, user, command and the site each belongs to. |
| `get_worker_log` | `view_logs` | read-only | Read the log of a background process (queue worker or daemon) on a Forge server (last lines only). |
| `restart_nginx` | `restart_services` | destructive | Restart Nginx on a Forge server. |
| `restart_php_fpm` | `restart_services` | destructive | Reload or restart PHP-FPM on a Forge server. |
| `restart_worker` | `manage_workers` | destructive | Restart a background process (queue worker or daemon) on a Forge server. |
| `stop_worker` | `manage_workers` | destructive | Stop a background process (queue worker or daemon) on a Forge server. |
| `start_worker` | `manage_workers` | write | Start a stopped background process (queue worker or daemon) on a Forge server. |
| `create_worker` | `configure_workers` | destructive | Create a background process (queue worker or daemon) on a Forge server, optionally for a site. |
| `update_worker` | `configure_workers` | destructive | Rename a background process (queue worker or daemon) and optionally replace its raw supervisor config. |
| `delete_worker` | `configure_workers` | destructive | Delete a background process (queue worker or daemon) from a Forge server. |
| `get_site_env` | `view_env` | read-only | Show the full .env file of a Forge site, including secret values. |
| `set_site_env_values` | `edit_env` | destructive | Set or remove individual keys in the .env file of a Forge site. |
| `run_site_command` | `run_commands` | destructive | Run a shell command in the directory of a Forge site and wait briefly for the result. |
| `get_command_result` | `view_logs` | read-only | Get the status, exit code and output of a command started with run_site_command. |
| `get_deployment_script` | `view` | read-only | Read the deployment script of a Forge site. |
| `update_deployment_script` | `configure_deployments` | destructive | Replace the deployment script of a Forge site with the given content. |
| `set_quick_deploy` | `configure_deployments` | write | Enable or disable quick deploy (push to deploy) for a Forge site. |
| `reset_deployment_state` | `deploy` | write | Reset the deployment state of a Forge site that is stuck in "deploying", so a new deployment can be started. |
| `reboot_server` | `reboot_server` | destructive | Reboot a Forge server. |
| `get_integration_status` | `view` | read-only | Show whether Horizon, Octane or Reverb is enabled and installed on a Forge site, with its backing processes. |
| `list_scheduled_jobs` | `view` | read-only | List scheduled jobs (cron) of a Forge server, or of one site when site_id is given: id, frequency, user and command. |
| `get_scheduled_job_output` | `view_logs` | read-only | Read the last output of a scheduled job on a Forge server, or on one site when site_id is given (last lines only). |
| `list_server_events` | `view` | read-only | List recent events of a Forge server: id, time, description and the user it ran as. |
| `get_server_event_output` | `view_logs` | read-only | Read the output of a Forge server event (last lines only). |
| `restart_service` | `restart_services` | destructive | Restart Redis, Supervisor, MySQL or Postgres on a Forge server. |
| `get_maintenance_mode` | `view` | read-only | Show whether Laravel maintenance mode is on for a Forge site, and whether it is being enabled or disabled. |
| `enable_maintenance_mode` | `maintenance_mode` | destructive | Put a Forge site into Laravel maintenance mode, so visitors get the maintenance response instead of the app. |
| `disable_maintenance_mode` | `maintenance_mode` | write | Take a Forge site out of Laravel maintenance mode. |
| `set_defaults` | `view` | write | Store a default organization, server and (optionally) site for this access token. |
| `get_defaults` | `view` | read-only | Show the default organization, server and site in effect and where they come from. |
| `clear_defaults` | `view` | write | Remove the defaults stored for this access token. |

Tools that take a server or site id resolve it against the Forge account you connected and check the token's permissions for that exact organization, server and site before acting. When only one organization is available, the `organization` argument may be omitted. When a default server or site is configured, `server_id` and `site_id` may be omitted too (see [Project defaults](#project-defaults)).

### Prompt: `diagnose_failed_deploy`

Arguments: `server_id`, `site_id` and `organization`, all optional when defaults are configured. It asks the agent to check the deployment status, list the last deployments, read the latest failed deployment log and the application log, then summarise the likely cause and propose a fix, without changing anything. It is offered only to tokens that have `view` and `view_logs`.

## Project defaults

If a repository always works with the same Forge site, put its ids in the project's `.mcp.json` as headers, and tools fill them in when the agent leaves them out:

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

The URL and token come from each developer's environment, so the file can be committed. If a referenced environment variable isn't set, Claude Code either fails to load the server or sends an empty value, depending on version, so write the `X-Forge-*` values as literals and set `FORGE_MCP_URL` / `FORGE_MCP_TOKEN` before starting Claude Code. An empty header value makes every call fail with `Invalid X-Forge-… header.` Alternatively, store defaults per access token with `set_defaults` or on the token's page in the dashboard. Headers, when present, replace token defaults entirely, and defaults never bypass a permission check. See [Project defaults](repo-docs/project-defaults.md).

## Claude Code plugin

```text
/plugin marketplace add abishekrsrikaanth/laravel-forge-mcp
/plugin install forge-mcp@laravel-forge-mcp
```

- `/forge-mcp:configure [site name or id]` connects the project (writing the `.mcp.json` above, never a token) and picks a default organization, server and site, saved as project headers or on your access token.
- `/forge-mcp:diagnose [site id]` diagnoses the latest deployment of a site, or the default site, without changing anything.

See [Claude Code plugin](repo-docs/claude-code-plugin.md).

## Coming from bretterer/forge-mcp-server

| bretterer/forge-mcp-server | Forge MCP |
|---|---|
| `get_deployments` | `list_deployments` |
| `toggle_quick_deploy` | `set_quick_deploy` (takes `enabled: true\|false`) |
| `get_server_load` | Not available: Forge's current API has no server-load endpoint |
| `list_servers`, `get_server`, `list_sites`, `get_site`, `deploy_site`, `get_deployment_script`, `update_deployment_script`, `reboot_server`, `reset_deployment_state` | Same name |

Forge MCP also adds worker management, `.env` editing, site commands, scheduled jobs, server events, service restarts and maintenance mode, each behind a permission.

## Configuration

Tunables live in `config/forge-mcp.php`. Each can be overridden with an environment variable (they are listed, commented out, in `.env.example`):

Empty or non-numeric values fall back to the default, and values below the minimum are raised to it: `FORGE_MCP_POLL_TIMEOUT` 1, the two cache TTLs 0, `FORGE_MCP_LOG_TAIL_DEFAULT` 1, `FORGE_MCP_LOG_TAIL_MAX` at least the default, `FORGE_MCP_AUDIT_RETENTION_DAYS` 1, `FORGE_MCP_SCRUB_MIN_LENGTH` 4.

| Variable | Default | What it sets |
|---|---|---|
| `FORGE_MCP_POLL_TIMEOUT` | `20` | Seconds to wait when polling Forge for a long-running operation (deploys, commands). |
| `FORGE_MCP_LIST_CACHE_TTL` | `60` | Seconds to cache Forge list responses (servers, sites, ...). |
| `FORGE_MCP_SECRETS_CACHE_TTL` | `600` | Seconds to cache the (encrypted) site secrets used for scrubbing. |
| `FORGE_MCP_LOG_TAIL_DEFAULT` | `200` | Log lines returned by log tools by default. |
| `FORGE_MCP_LOG_TAIL_MAX` | `1000` | Maximum log lines a log tool returns. |
| `FORGE_MCP_AUDIT_RETENTION_DAYS` | `90` | Days to keep audit log entries. |
| `FORGE_MCP_SCRUB_MIN_LENGTH` | `8` | Minimum length of a secret value that is scrubbed from output. |

Run `php artisan config:cache` again after changing them when the config is cached.

## Roadmap

- Phase 2: OAuth for MCP clients and Claude.ai connectors, access token expiry, notifications for risky actions, and "ask first" confirmations.
- Phase 3: teams.

## Documentation

The complete documentation lives in [repo-docs/](repo-docs/README.md):

- [Getting started](repo-docs/getting-started.md)
- [Installation](repo-docs/installation.md) and [configuration](repo-docs/configuration.md)
- [Forge API token](repo-docs/forge-api-token.md) and [connecting clients](repo-docs/connecting-clients.md)
- [Project defaults](repo-docs/project-defaults.md) and the [Claude Code plugin](repo-docs/claude-code-plugin.md)
- [Dashboard](repo-docs/dashboard.md), [permissions](repo-docs/permissions.md) and [security](repo-docs/security.md)
- [Tool reference](repo-docs/tools.md)
- [Migrating from bretterer/forge-mcp-server](repo-docs/migrating-from-bretterer.md)
- [Troubleshooting](repo-docs/troubleshooting.md) and [contributing](repo-docs/contributing.md)

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) and the [contributor guide](repo-docs/contributing.md). To report a vulnerability, see [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
