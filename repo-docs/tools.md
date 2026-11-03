# Tool reference

Forge MCP exposes 38 tools and one prompt. This page lists each tool's capability, kind, arguments and behaviour. The first paragraph under each tool heading is the description agents see.

## Conventions

- **Capability:** the [permission](permissions.md) the access token needs on the target. A tool is listed to the agent only when the token has that capability somewhere.
- **Kind:** `read-only` tools never change anything (annotated `readOnlyHint`). `destructive` tools are annotated `destructiveHint`, so clients can ask for confirmation. `write` tools change state without being destructive.
- **`organization`:** every tool that works inside an organization takes an optional `organization` slug (from `list_organizations`). It may be omitted when exactly one organization is exposed; with several, omitting it returns ``Several organizations are available (…). Pass the `organization` argument.`` An organization that is not exposed is denied.
- **Ids are verified:** a `server_id` must belong to the organization (`Server 12 is not in organization acme.`) and a `site_id` to the server (`Site 34 is not on server 12.`), using Forge's own lists, before the permission check.
- **Output** is plain text, scrubbed of secrets (see [Security](security.md#secret-scrubbing)), possibly followed by `Warning: …` lines.
- **`lines`:** log tools return the last `lines` lines (default 200, maximum 1000; see [Configuration](configuration.md#forge-mcp-tunables)). When output is cut, it starts with `[showing last N of M lines]`.
- **Denials** return `<Capability> is not permitted on <target> for this access token. The user can enable it in the Forge MCP dashboard.` and are recorded in the audit log.
- **Queued actions:** restarts, reboots, worker actions and maintenance mode changes are queued by Forge; the result confirms that Forge accepted them, not that they have finished.

## Summary

| Tool | Capability | Kind |
|---|---|---|
| [`list_organizations`](#list_organizations) | `view` | read-only |
| [`list_servers`](#list_servers) | `view` | read-only |
| [`get_server`](#get_server) | `view` | read-only |
| [`list_sites`](#list_sites) | `view` | read-only |
| [`get_site`](#get_site) | `view` | read-only |
| [`get_deployment_status`](#get_deployment_status) | `view` | read-only |
| [`list_deployments`](#list_deployments) | `view` | read-only |
| [`get_deployment_log`](#get_deployment_log) | `view_logs` | read-only |
| [`deploy_site`](#deploy_site) | `deploy` | write |
| [`get_site_log`](#get_site_log) | `view_logs` | read-only |
| [`list_workers`](#list_workers) | `view` | read-only |
| [`get_worker_log`](#get_worker_log) | `view_logs` | read-only |
| [`restart_nginx`](#restart_nginx) | `restart_services` | destructive |
| [`restart_php_fpm`](#restart_php_fpm) | `restart_services` | destructive |
| [`restart_worker`](#restart_worker) | `manage_workers` | destructive |
| [`stop_worker`](#stop_worker) | `manage_workers` | destructive |
| [`start_worker`](#start_worker) | `manage_workers` | write |
| [`create_worker`](#create_worker) | `configure_workers` | destructive |
| [`update_worker`](#update_worker) | `configure_workers` | destructive |
| [`delete_worker`](#delete_worker) | `configure_workers` | destructive |
| [`get_site_env`](#get_site_env) | `view_env` | read-only |
| [`set_site_env_values`](#set_site_env_values) | `edit_env` | destructive |
| [`run_site_command`](#run_site_command) | `run_commands` | destructive |
| [`get_command_result`](#get_command_result) | `view_logs` | read-only |
| [`get_deployment_script`](#get_deployment_script) | `view` | read-only |
| [`update_deployment_script`](#update_deployment_script) | `configure_deployments` | destructive |
| [`set_quick_deploy`](#set_quick_deploy) | `configure_deployments` | write |
| [`reset_deployment_state`](#reset_deployment_state) | `deploy` | write |
| [`reboot_server`](#reboot_server) | `reboot_server` | destructive |
| [`get_integration_status`](#get_integration_status) | `view` | read-only |
| [`list_scheduled_jobs`](#list_scheduled_jobs) | `view` | read-only |
| [`get_scheduled_job_output`](#get_scheduled_job_output) | `view_logs` | read-only |
| [`list_server_events`](#list_server_events) | `view` | read-only |
| [`get_server_event_output`](#get_server_event_output) | `view_logs` | read-only |
| [`restart_service`](#restart_service) | `restart_services` | destructive |
| [`get_maintenance_mode`](#get_maintenance_mode) | `view` | read-only |
| [`enable_maintenance_mode`](#enable_maintenance_mode) | `maintenance_mode` | destructive |
| [`disable_maintenance_mode`](#disable_maintenance_mode) | `maintenance_mode` | write |

## Organizations, servers and sites

### `list_organizations`

List the Forge organizations this MCP server can access. Use a slug as the `organization` argument of other tools.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Only show this organization (slug). |

Returns one line per organization (`- slug: name`). Only organizations that are exposed on the Forge connection **and** where the token has `view` are listed. This tool is also listed when no organization is exposed yet, so the agent can tell the user to expose one.

### `list_servers`

List the Forge servers in an organization: id, name, IP address, PHP version and connection status.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |

Returns one line per server: `id name ip php:version connection-status`, sorted by name. Servers the token cannot `view` (at server, organization or global level) are left out.

### `get_server`

Show details of one Forge server: name, IP address, provider, region, PHP and Ubuntu versions, database type, readiness.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id (from list_servers). |

Returns `name`, `ip_address`, `provider`, `region`, `php_version`, `ubuntu_version`, `database_type` and `is_ready`. The server id is checked against the organization's server list first.

### `list_sites`

List Forge sites: id, name, server, PHP version and deployment status. Pass server_id to list one server, otherwise all sites in the organization.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | no | Only list sites on this server. |

Returns one line per site: `id name server:id php:version deploy:status`, sorted by name. Each site is checked individually, so sites the token cannot `view` are left out.

### `get_site`

Show details of one Forge site: name, URL, repository, PHP version, deployment status, quick deploy and directories.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |

Returns `name`, `url`, `repository`, `php_version`, `deployment_status`, `quick_deploy`, `root_directory` and `web_directory`. The site must be on the given server.

## Deployments

### `get_deployment_status`

Show the current deployment status of a Forge site (idle when no deployment is running).

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |

Returns `status` (`idle` when nothing is running) and `started_at`.

### `list_deployments`

List recent deployments of a Forge site: id, status, start time, commit hash and commit message.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |
| `limit` | integer | no | Number of deployments to return (default 10, max 50). |

Returns one line per deployment: `id status started_at short-hash first-line-of-commit-message`. `limit` is clamped to 1–50.

### `get_deployment_log`

Read the output log of one deployment of a Forge site (last lines only). Secrets are redacted.

- **Capability:** `view_logs`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |
| `deployment_id` | integer | yes | Deployment id (from list_deployments). |
| `lines` | integer | no | Number of trailing lines to return. Default 200 (`FORGE_MCP_LOG_TAIL_DEFAULT`), capped at 1000 (`FORGE_MCP_LOG_TAIL_MAX`). |

Returns the last `lines` lines of the log, prefixed with `[showing last N of M lines]` when it was cut.

### `deploy_site`

Deploy a Forge site and wait briefly for the result. Returns the final status and the last lines of the deployment log, or a hint to poll get_deployment_status if it is still running.

- **Capability:** `deploy`
- **Kind:** write

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |

Triggers a deployment, then polls Forge for up to `FORGE_MCP_POLL_TIMEOUT` seconds (default 20). If the deployment finishes in time, the result is its final status and the last 50 lines of its log. If not, the result is `Deployment {id} is still {status}. Call get_deployment_status to check progress.` Once the deployment has started, a failure while checking progress still reports the deployment id, so the agent does not deploy twice.

### `reset_deployment_state`

Reset the deployment state of a Forge site that is stuck in "deploying", so a new deployment can be started.

- **Capability:** `deploy`
- **Kind:** write

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |

Use this only when Forge shows a site as deploying but no deployment is actually running.

## Logs

### `get_site_log`

Read the application, nginx access or nginx error log of a Forge site (last lines only). Secrets are redacted.

- **Capability:** `view_logs`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |
| `type` | string | yes | Which log to read. One of: `"application"`, `"nginx-access"`, `"nginx-error"`. |
| `lines` | integer | no | Number of trailing lines to return. Default 200 (`FORGE_MCP_LOG_TAIL_DEFAULT`), capped at 1000 (`FORGE_MCP_LOG_TAIL_MAX`). |

`application` is the Laravel log; `nginx-access` and `nginx-error` are the site's Nginx logs.

## Workers

### `list_workers`

List the background processes (queue workers, daemons) on a Forge server: id, status, process count, user, command and the site each belongs to. Pass site_id to list one site.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `site_id` | integer | no | Only list workers belonging to this site. |

Returns one line per process: `id status xprocesses user command site:ids`. Forge does not record which site a worker belongs to, so Forge MCP infers the candidate sites from the worker's directory (see [Workers and sites](permissions.md#workers-and-sites)). A worker is listed only if the token can `view` every candidate site, or the server when there is no candidate. With `site_id`, only workers whose candidates include that site are listed.

### `get_worker_log`

Read the log of a background process (queue worker or daemon) on a Forge server (last lines only). Secrets are redacted.

- **Capability:** `view_logs`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `worker_id` | integer | yes | Background process id (from list_workers). |
| `lines` | integer | no | Number of trailing lines to return. Default 200 (`FORGE_MCP_LOG_TAIL_DEFAULT`), capped at 1000 (`FORGE_MCP_LOG_TAIL_MAX`). |

Needs `view_logs` on every candidate site of the worker (or on the server when it has none). Secrets of every candidate site are scrubbed from the output.

### `restart_worker`

Restart a background process (queue worker or daemon) on a Forge server. Running jobs are interrupted.

- **Capability:** `manage_workers`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `worker_id` | integer | yes | Background process id (from list_workers). |

Needs `manage_workers` on every candidate site of the worker (or on the server when it has none). The change is queued in Forge; the result confirms it was queued.

### `stop_worker`

Stop a background process (queue worker or daemon) on a Forge server. It stays stopped until started again.

- **Capability:** `manage_workers`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `worker_id` | integer | yes | Background process id (from list_workers). |

Same permission rule as `restart_worker`.

### `start_worker`

Start a stopped background process (queue worker or daemon) on a Forge server.

- **Capability:** `manage_workers`
- **Kind:** write

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `worker_id` | integer | yes | Background process id (from list_workers). |

Same permission rule as `restart_worker`.

### `create_worker`

Create a background process (queue worker or daemon) on a Forge server, optionally for a site. Runs as the forge user unless user=root is given (root needs the permission on the whole server). With site_id, a directory must be inside the site root. This executes an arbitrary command on the server.

- **Capability:** `configure_workers`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `command` | string | yes | Command to run, e.g. "php artisan queue:work --tries=3". |
| `site_id` | integer | no | Site the process belongs to. Permission is then checked on that site. |
| `name` | string | no | Process name. Defaults to the command. |
| `user` | string | no | System user to run as. root needs the permission on the whole server. One of: `"forge"`, `"root"`. Default: `"forge"`. |
| `processes` | integer | no | Number of processes (1-20). Default: `1`. |
| `directory` | string | no | Working directory (absolute). With site_id it must be inside the site root. Leave unset to let Forge decide. |

- Without `site_id`, the permission is checked on the server.
- With `site_id`, it is checked on the site; a `directory` must be absolute, must not contain `..`, and must be the site's root directory or below it.
- `user: root` additionally needs `configure_workers` on the whole server, not only on the site.
- `command`, `name`, `directory` and `user` must be single lines without control characters.

### `update_worker`

Rename a background process (queue worker or daemon) and optionally replace its raw supervisor config. A wrong config can break the process; the config is recorded in the audit log.

- **Capability:** `configure_workers`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `worker_id` | integer | yes | Background process id (from list_workers). |
| `name` | string | yes | New name for the process. |
| `config` | string | no | Raw supervisor configuration to replace the current one (needs the permission on the whole server). Omit to keep it. |

Renaming needs `configure_workers` on every candidate site of the worker. Passing `config` (raw supervisor configuration) additionally needs the permission on the whole server, because a supervisor config can set any user and command. `name` must be a single line; `config` must not contain NUL bytes. The arguments, including `config`, are stored in the audit log (scrubbed and truncated like all audited arguments).

### `delete_worker`

Delete a background process (queue worker or daemon) from a Forge server. This stops it and removes its configuration.

- **Capability:** `configure_workers`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `worker_id` | integer | yes | Background process id (from list_workers). |

Same permission rule as `restart_worker`, with `configure_workers`.

## Services

### `restart_nginx`

Restart Nginx on a Forge server. Briefly interrupts web serving on every site on that server.

- **Capability:** `restart_services`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id (from list_servers). |

Server-level only: `restart_services` cannot be granted per site. The restart is queued in Forge.

### `restart_php_fpm`

Reload or restart PHP-FPM on a Forge server. The PHP version defaults to the site version (when site_id is given) or the server version; pass version (e.g. php84) to override. Restart briefly interrupts PHP requests; reload is gentler.

- **Capability:** `restart_services`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id (from list_servers). |
| `action` | string | no | reload (default) or restart. One of: `"reload"`, `"restart"`. Default: `"reload"`. |
| `site_id` | integer | no | Optional site id, used only to detect the PHP version. |
| `version` | string | no | PHP version such as php84. Overrides detection. |

Server-level only. The PHP version is taken from `version`, else from the site (when `site_id` is given; the site must be on the server, but permission is still checked on the server), else from the server. Supported versions: `php5`, `php56-old`, `php56`, `php70` … `php85`. If no version can be determined, pass `version`.

### `restart_service`

Restart Redis, Supervisor, MySQL or Postgres on a Forge server. Briefly interrupts that service for everything on the server. Use restart_nginx and restart_php_fpm for those services.

- **Capability:** `restart_services`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id (from list_servers). |
| `service` | string | yes | Service to restart. One of: `"redis"`, `"supervisor"`, `"mysql"`, `"postgres"`. |

Server-level only.

### `reboot_server`

Reboot a Forge server. Every site and service on it is unavailable until it is back up.

- **Capability:** `reboot_server`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id (from list_servers). |

Server-level only: `reboot_server` cannot be granted per site. The reboot is queued in Forge.

## Environment

### `get_site_env`

Show the full .env file of a Forge site, including secret values. Do not repeat secret values back to the user unless they ask for them.

- **Capability:** `view_env`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |

This is the only tool whose output is **not** scrubbed of `.env` values: it exists to show them. Only the Forge API token itself is still removed. Keep `view_env` off unless an agent really needs it.

### `set_site_env_values`

Set or remove individual keys in the .env file of a Forge site. Comments and other keys are kept. Returns the names of the keys added, changed and removed (never values). Changes take effect on the next deployment or config reload. Edits made to the .env outside this tool at the same moment may be overwritten.

- **Capability:** `edit_env`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |
| `set` | object | no | Keys to add or change, as an object of KEY => string value. |
| `remove` | array of string | no | Keys to remove. |

- Pass `set`, `remove` or both, with at least one key. Keys must match `[A-Za-z_][A-Za-z0-9_]*`, values must be strings without NUL characters, and a key cannot be both set and removed.
- Forge MCP reads the current `.env`, changes only the given keys (comments, ordering and other keys stay as they are) and writes it back. If the current file cannot be read, nothing is written.
- Values are written bare when they are plain, otherwise quoted (single quotes for values containing `$`, so they are not interpolated).
- The result lists key **names** only: `Added: … Changed: … Removed: …`. Values never appear in the output or in the audit log (every `set` value is stored as `[REDACTED]`).
- Edits to the same site are serialised; a second edit waits up to 10 seconds and then fails with `Another .env edit for this site is in progress; try again.`
- Both the old and the new values are remembered for scrubbing, so a rotated-out secret stays masked in later output.

## Commands

### `run_site_command`

Run a shell command in the directory of a Forge site and wait briefly for the result. DANGEROUS: executes arbitrary code on a production server, so run only commands the user asked for. Returns exit code and output, or a hint to call get_command_result if it is still running.

- **Capability:** `run_commands`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |
| `command` | string | yes | Shell command to run in the site directory (max 2000 characters). |

- `command` must be a single line (join several commands with `&&` or `;`), at most 2,000 characters, without NUL bytes.
- After queueing the command, Forge MCP identifies its id and polls for up to `FORGE_MCP_POLL_TIMEOUT` seconds. The result is `exit {code}, status {status}` followed by the output tail (and any error output), or `Command {id} is still {status}. Call get_command_result with command_id {id}.`
- If the id cannot be determined, the result says the command was queued and asks the user to check the site's commands in Forge **before running it again**.
- The command text is stored in the audit log (scrubbed).

### `get_command_result`

Get the status, exit code and output of a command started with run_site_command. Secrets are redacted.

- **Capability:** `view_logs`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |
| `command_id` | integer | yes | Command id returned by run_site_command. |
| `lines` | integer | no | Number of trailing lines to return. Default 200 (`FORGE_MCP_LOG_TAIL_DEFAULT`), capped at 1000 (`FORGE_MCP_LOG_TAIL_MAX`). |

Returns the same report as `run_site_command`. The command must belong to the given site.

## Deployment settings

### `get_deployment_script`

Read the deployment script of a Forge site. Secrets found in the script are replaced with [REDACTED] markers.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |

Known secrets in the script are replaced with `[REDACTED…]` markers, so a script read here is not always safe to write back unchanged.

### `update_deployment_script`

Replace the deployment script of a Forge site with the given content. The next deployment runs the new script. Returns only a line count, never the script.

- **Capability:** `configure_deployments`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |
| `content` | string | yes | The full new deployment script (bash), up to 50,000 characters. |

`content` must be non-empty, at most 50,000 characters and free of NUL bytes. The result only reports the number of lines. The script is stored in the audit log (scrubbed and truncated to 4,000 characters).

### `set_quick_deploy`

Enable or disable quick deploy (push to deploy) for a Forge site. When enabled, a push to the site repository triggers a deployment.

- **Capability:** `configure_deployments`
- **Kind:** write

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |
| `enabled` | boolean | yes | true to enable quick deploy, false to disable it. |

Replaces `toggle_quick_deploy` from bretterer/forge-mcp-server; pass the desired state explicitly.

## Integrations, scheduled jobs and events

### `get_integration_status`

Show whether Horizon, Octane or Reverb is enabled and installed on a Forge site, with its backing processes.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |
| `integration` | string | yes | Which integration to inspect. One of: `"horizon"`, `"octane"`, `"reverb"`. |

Returns `{integration}: enabled=… installed=…` followed by one line per backing process (`id status command`).

### `list_scheduled_jobs`

List scheduled jobs (cron) of a Forge server, or of one site when site_id is given: id, frequency, user and command.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `site_id` | integer | no | Forge site id; omit to list the server-level jobs. |

Returns one line per job: `id frequency user command` (the cron expression is shown for custom frequencies), at most 100 lines. Without `site_id`, server-level jobs may touch any site, so `view` is required on the server **and** on every site of the server.

### `get_scheduled_job_output`

Read the last output of a scheduled job on a Forge server, or on one site when site_id is given (last lines only). Secrets are redacted.

- **Capability:** `view_logs`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `job_id` | integer | yes | Scheduled job id (from list_scheduled_jobs). |
| `site_id` | integer | no | Forge site id when the job belongs to a site. |
| `lines` | integer | no | Number of trailing lines to return. Default 200 (`FORGE_MCP_LOG_TAIL_DEFAULT`), capped at 1000 (`FORGE_MCP_LOG_TAIL_MAX`). |

Without `site_id`, `view_logs` is required on the server and on every site of the server.

### `list_server_events`

List recent events of a Forge server: id, time, description and the user it ran as.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `limit` | integer | no | Number of events to return (default 20, max 50). |

Returns one line per event: `id time description (user)`. `limit` is clamped to 1–50. Events can concern any site, so `view` is required on the server and on every site of the server.

### `get_server_event_output`

Read the output of a Forge server event (last lines only). Secrets of the server sites are redacted.

- **Capability:** `view_logs`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id. |
| `event_id` | integer | yes | Event id (from list_server_events). |
| `lines` | integer | no | Number of trailing lines to return. Default 200 (`FORGE_MCP_LOG_TAIL_DEFAULT`), capped at 1000 (`FORGE_MCP_LOG_TAIL_MAX`). |

`view_logs` is required on the server and on every site of the server.

## Maintenance

### `get_maintenance_mode`

Show whether Laravel maintenance mode is on for a Forge site, and whether it is being enabled or disabled.

- **Capability:** `view`
- **Kind:** read-only

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |

Returns `Maintenance mode: on` or `off`, with `(status: enabling)` or `(status: disabling)` while a change is in progress.

### `enable_maintenance_mode`

Put a Forge site into Laravel maintenance mode, so visitors get the maintenance response instead of the app. Optionally set a bypass secret, an HTTP status (304, 307, 410 or 503; default 503) and a redirect (relative path or http(s) URL). The change is applied asynchronously; check get_maintenance_mode.

- **Capability:** `maintenance_mode`
- **Kind:** destructive

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |
| `secret` | string | no | Optional bypass secret (max 128 characters, no whitespace). |
| `status` | integer | no | HTTP status returned to visitors: 304, 307, 410 or 503. One of: `304`, `307`, `410`, `503`. Default: `503`. |
| `redirect` | string | no | Optional redirect target: a relative path starting with "/" or an http(s) URL. |

`secret` is optional, at most 128 characters without whitespace, and is stored in the audit log as `[REDACTED]`. `redirect` is a relative path starting with `/` or an `http(s)` URL (max 2,048 characters). The change is applied asynchronously; call `get_maintenance_mode` to confirm.

### `disable_maintenance_mode`

Take a Forge site out of Laravel maintenance mode. The change is applied asynchronously; check get_maintenance_mode.

- **Capability:** `maintenance_mode`
- **Kind:** write

| Argument | Type | Required | Description |
|---|---|---|---|
| `organization` | string | no | Organization slug. Optional when only one organization is exposed. |
| `server_id` | integer | yes | Forge server id the site lives on. |
| `site_id` | integer | yes | Forge site id (from list_sites). |

Applied asynchronously; call `get_maintenance_mode` to confirm.

## Prompt: `diagnose_failed_deploy`

Guided, read-only diagnosis of the most recent deployment of a Forge site.

| Argument | Required | Description |
|---|---|---|
| `organization` | no | Organization slug. May be omitted when only one is available. Must be an exposed organization. |
| `server_id` | yes | Forge server id the site lives on. |
| `site_id` | yes | Forge site id (from `list_sites`). |

The prompt asks the agent to call `get_deployment_status`, then `list_deployments` (limit 3), then `get_deployment_log` for the latest failed deployment, then `get_site_log` with type `application` (100 lines); to summarise the most likely cause in 3–5 bullets, quote the decisive log lines and propose a fix; and not to deploy or change anything. It is offered only to tokens that have both `view` and `view_logs` somewhere. The tools it names are still subject to the token's permissions on that site.
