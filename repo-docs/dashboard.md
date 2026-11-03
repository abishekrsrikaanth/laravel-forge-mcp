# Dashboard

The dashboard is where you, the owner, set Forge MCP up and keep an eye on it. Agents never use it: they only talk to `/mcp`. The sidebar has **Dashboard**, **Forge connection**, **Access tokens** and **Audit log**; your profile, password, two-factor authentication and passkeys are under **Settings**.

## Setup (`/setup`)

The first-run page. It creates the owner account (name, email, password) and logs you in. It works exactly once: as soon as an owner exists, `/setup` returns 404. Until then, every dashboard page redirects to it, and anyone who can reach the app can claim it, so complete it right after the first deploy. There is no public sign-up page.

## Dashboard home (`/dashboard`)

- **Forge connection:** whether Forge is connected and as whom. If Forge reported a problem (for example a revoked token), the error is shown here with a **Reconnect** button.
- **Active MCP tokens:** how many access tokens exist, with a link to manage them.
- **Recent activity:** the last ten tool calls, with tool, summary, outcome (`success`, `denied` or `error`) and time, and a link to the full audit log.

## Forge connection (`/forge`)

- **Connect to Forge / Replace token:** paste a Forge API token. Forge MCP validates it against Forge, reads your Forge user and organizations, and stores the token encrypted. It is never shown again; the page shows only `•••• ` plus its last four characters, the Forge user name and email, and when it was validated.
- **Exposed organizations:** tick the organizations MCP clients may reach, then **Save**. Agents cannot see or act on an organization that is not ticked, whatever their permissions say. When the token can see exactly one organization, it is ticked for you on first connect; replacing the token keeps your selection for organizations the new token can still see.
- **Required token scopes:** each Forge scope and the capabilities that need it (see [Forge API token](forge-api-token.md)).
- **Disconnect:** deletes the stored Forge token. Every tool then answers `Forge is not connected.`

## Access tokens (`/tokens`)

- **New token:** a name and, once you have tokens, **Copy permissions from** (start read-only, or copy every rule of an existing token). The new token is shown once in a green box: copy it then.
- **Your tokens:** each token with when it was last used and a permission summary, for example `Read-only` or `Deploy, Edit .env (2 scoped)`. A bare capability name means it is allowed globally; `(N scoped)` counts allow rules for an organization, server or site.
- **Permissions:** opens the permission editor for the token.
- **Revoke:** deletes the token after a confirmation. Agents using it lose access immediately. Its audit log entries stay.
- **Connect a client:** a `claude mcp add` command and a JSON config for a server named `forge-mcp`, built from `APP_URL`. Right after you create a token, they include it.

## Token permissions (`/tokens/{id}/permissions`)

See [Permissions](permissions.md) for how rules are resolved. The page has four parts.

### Global permissions

A switch per capability, grouped into **Read**, **Actions** and **Risky**, with a description of each. Turning a switch on allows the capability everywhere the token can reach; turning it off removes the global allow (the capability is then denied unless an override allows it). Turning on a risky capability requires ticking an acknowledgement ("I understand this lets agents …") before **Save global permissions** accepts it.

If Forge is not connected, or no organization is exposed, a warning explains that nothing is reachable yet.

### Overrides

Allow or deny one capability for a narrower scope:

1. **Organization:** one of the exposed organizations.
2. **Server (optional):** leave as **Whole organization**, or pick a server (loaded live from Forge).
3. **Site (optional):** leave as **Whole server**, or pick a site on that server.
4. **Capability** and **Rule:** **Allow**, **Deny**, or **Inherit** (removes the override at that scope).

Allowing a risky capability needs the acknowledgement tick here too. `restart_services` and `reboot_server` cannot be set for a single site: they are server-level only, and the page refuses a site-level rule for them.

### Current overrides

Every organization, server and site rule of the token, with a **Remove** button.

### Defaults

A default **Organization**, **Server** and optional **Site** for the token, picked from your Forge servers and sites. Tools use them when the agent leaves those arguments out, unless the client sends project headers. **Save defaults** stores them, **Clear** removes them, and the card shows what is stored. See [Project defaults](project-defaults.md).

## Audit log (`/audit`)

Every MCP tool call that reached the permission check, newest first, 50 per page. Filter by **Tool**, **Outcome** (`success`, `denied`, `error`) and **Token**; filters are kept in the URL. Each row shows the time, tool, outcome, token name, summary and duration; expand it to see the organization, server and site and the (scrubbed, possibly truncated) arguments. Entries older than the retention period (90 days by default, `FORGE_MCP_AUDIT_RETENTION_DAYS`) are pruned daily. See [Security](security.md#audit-log) for what is stored.
