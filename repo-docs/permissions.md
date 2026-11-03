# Permissions

Every access token has its own permissions. A permission is a **capability** (what the agent may do) allowed or denied at a **scope** (where). Forge MCP checks the permission on the exact organization, server and site before every Forge call.

## Capabilities

There are twelve capabilities, grouped into three tiers. They are independent: none implies another (`deploy` does not imply `view_logs`, `edit_env` does not imply `view_env`).

| Capability | Tier | Default | Per site | What it allows | Tools |
|---|---|---|---|---|---|
| `view` | read | allowed | yes | Browse organizations, servers and sites, and check deployment status. | `list_organizations`, `list_servers`, `get_server`, `list_sites`, `get_site`, `get_deployment_status`, `list_deployments`, `list_workers`, `get_deployment_script`, `get_integration_status`, `list_scheduled_jobs`, `list_server_events`, `get_maintenance_mode` |
| `view_logs` | read | allowed | yes | Read site, deployment and worker logs. | `get_deployment_log`, `get_site_log`, `get_worker_log`, `get_command_result`, `get_scheduled_job_output`, `get_server_event_output` |
| `deploy` | action | off | yes | Trigger deployments. | `deploy_site`, `reset_deployment_state` |
| `restart_services` | action | off | **no** | Restart services such as PHP, Nginx and databases. | `restart_nginx`, `restart_php_fpm`, `restart_service` |
| `manage_workers` | action | off | yes | Restart, start and stop existing queue workers. | `restart_worker`, `stop_worker`, `start_worker` |
| `maintenance_mode` | action | off | yes | Put a site into and out of maintenance mode. | `enable_maintenance_mode`, `disable_maintenance_mode` |
| `view_env` | risky | off | yes | Read the .env file, including secrets. | `get_site_env` |
| `edit_env` | risky | off | yes | Change the .env file. | `set_site_env_values` |
| `run_commands` | risky | off | yes | Run arbitrary commands in a site directory. | `run_site_command` |
| `configure_workers` | risky | off | yes | Create, change and delete background processes (runs arbitrary commands, optionally as root). | `create_worker`, `update_worker`, `delete_worker` |
| `configure_deployments` | risky | off | yes | Change the deployment script and quick deploy. | `update_deployment_script`, `set_quick_deploy` |
| `reboot_server` | risky | off | **no** | Reboot the whole server. | `reboot_server` |

- **Read** capabilities only look.
- **Action** capabilities change the running state of a site or server in ways you would routinely do yourself.
- **Risky** capabilities expose secrets or let the agent run arbitrary code or change how deployments work. The dashboard asks you to tick an acknowledgement before allowing one.

## Defaults

A new access token gets a global allow for `view` and `view_logs`, and nothing else. Alternatively, when creating a token you can copy every rule from one of your existing tokens.

## Scopes and rules

A rule says "allow" or "deny" for one capability at one scope:

| Scope | Applies to |
|---|---|
| Global | Everything the token can reach. Set with the switches under **Global permissions**. |
| Organization | One exposed Forge organization. |
| Server | One server in an organization. |
| Site | One site on a server. |

Rules other than global are called overrides and are set under **Overrides** on the token's permission page (see [Dashboard](dashboard.md#token-permissions-tokensidpermissions)).

## How a permission is decided

For a call on a target (an organization, a server, or a site on a server):

1. The organization must be **exposed** on the Forge connection. If it is not, everything is denied, whatever the rules say.
2. The **most specific** matching rule wins: **site > server > organization > global**. A site rule matches that site; a server rule matches the server and every site on it; an organization rule matches everything in the organization.
3. If no rule matches, the capability is **denied**.

Turning a global switch off removes the global rule; it does not add a deny. So "global off, server allow" means allowed on that server only, and "global on, site deny" means allowed everywhere except that site.

Example for `deploy`, with no global rule, an allow on server 12 and a deny on site 34 (which is on server 12):

- Site 34 on server 12: **denied** (site rule).
- Site 35 on server 12: **allowed** (server rule).
- Any site on server 13: **denied** (no rule).

The level of the check depends on the tool. Site tools (deployments, site logs, `.env`, commands, maintenance mode, deployment settings) check the site. Server tools (restarts, reboot, `get_server`) check the server, so site rules never apply to them: a site-level `view` allow does not let the agent call `get_server` on its server.

## Which tools an agent sees

A tool appears in the agent's tool list only if the token's capability is allowed somewhere it can reach: there is an allow rule for it (outside unexposed organizations) and at least one organization is exposed. Seeing a tool does not mean it works everywhere; each call is still checked against the exact target. `list_organizations` is also listed when no organization is exposed, so the agent can explain what is missing.

The `diagnose_failed_deploy` prompt is offered only to tokens that have both `view` and `view_logs` somewhere.

List tools filter per item: `list_servers`, `list_sites` and `list_workers` leave out servers, sites and workers the token cannot `view`, and `list_organizations` leaves out organizations it cannot `view`.

## Server-level-only operations

Some operations affect every site on a server, so they need the permission at **server** level (a server, organization or global rule), never only a site rule:

- `restart_services` and `reboot_server`: cannot be granted per site at all. Site rules for them are refused by the dashboard and ignored by the permission check.
- `create_worker` with `user: root`: a root process can touch every site, so `configure_workers` is needed on the server as well as on the site.
- `update_worker` with `config`: raw supervisor configuration can set any user and command, so `configure_workers` is needed on the server.
- `create_worker` with `site_id` and a `directory`: the directory must be the site's root directory or below it, so a site-level grant cannot start processes elsewhere on the server.

## Workers and sites

Forge does not record which site a background process (queue worker or daemon) belongs to, so Forge MCP infers **candidate sites**:

1. If the worker has an explicit site, that site.
2. Otherwise, the site whose root directory most closely contains the worker's directory, plus every site whose root directory lies below the worker's directory.
3. If nothing matches, there are no candidates and **server-level** rules apply.

An action on a worker (`get_worker_log`, `restart_worker`, `stop_worker`, `start_worker`, `update_worker`, `delete_worker`) is allowed only if **every** candidate site permits the capability. `list_workers` shows a worker only if every candidate site permits `view`. Output is scrubbed with the secrets of every candidate site.

## Server-wide reads need every site

Some reads cover data from any site on a server without naming one: server-level scheduled jobs and their output (`list_scheduled_jobs` and `get_scheduled_job_output` without `site_id`), and server events and their output (`list_server_events`, `get_server_event_output`). These need the capability on the server **and** on every site of that server. A single site-level deny on that server blocks them. Pass `site_id` to the scheduled-job tools to read one site's jobs with that site's permission.

## Worked examples

### Deploy staging only

Goal: the agent may deploy every site on the staging server, and nothing else.

1. Leave `deploy` off under **Global permissions**.
2. Add an override: organization `acme`, server `staging`, site **Whole server**, capability **Deploy**, rule **Allow**.

`deploy_site` and `reset_deployment_state` now work on every site of the staging server and are denied elsewhere. To allow one site only, pick that site instead of **Whole server**.

### Read-only CI token

Goal: a CI job may read deployment status and logs, nothing more.

1. Create a token named `CI - read only` and choose **Start read-only**. It has `view` and `view_logs` globally.
2. Optionally narrow it to one organization: turn both global switches off, then add organization overrides allowing **View** and **View logs** for that organization.

### Allow everything except production

Goal: the agent may do anything on staging and development servers, but nothing on the production server.

1. Turn on the capabilities you want under **Global permissions** (risky ones need the acknowledgement).
2. For each capability you turned on, add an override: organization `acme`, server `production`, capability, rule **Deny**. Rules are per capability, so one deny per capability is needed.

A server deny covers every site on that server, because nothing more specific allows it. If production lives in its own Forge organization, it is simpler to leave that organization unexposed, or to add organization-level denies.

## The shared `forge` user

Every site on a Forge server runs as the same Linux user, `forge`. A risky capability granted on one site (running commands, editing `.env`, configuring workers, changing the deployment script) runs code as that user, which can read and change the other sites on the same server. A site-level grant limits which site the agent *names*, not what the code it runs can reach.

Grant risky capabilities per server, not per site, unless you trust the agent with every site on that server. Forge MCP already requires server-level permission for restarts, reboots, root workers and raw worker configuration.
