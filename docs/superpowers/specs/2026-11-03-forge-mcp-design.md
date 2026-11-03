# Forge MCP — Design

*Working name: "Forge MCP" (placeholder — rename freely).*
*Date: 2026-11-03 · Status: draft for approval*

## 1. Intent

Developers who build apps with AI coding agents (Claude Code, Cursor, etc.) and host on Laravel Forge keep breaking flow to log in to Forge, find the server, find the site, and click a button: restart nginx, reload php-fpm, deploy, check a log, tweak `.env`, restart a queue worker.

Forge MCP is an **open-source, self-hosted Laravel app** that holds a user's Forge API token and exposes Forge operations as an **MCP server**, so the agent they're already talking to can do those things directly.

It is built for the author first and for any Forge user with the same itch second. Because strangers will run it against production servers, **safety and clarity of what the agent may do are as important as the features.**

## 2. Goals, non-goals, anti-goals

**Goals (v1)**
- A Forge user can deploy the app (typically via Forge itself), log in to a web dashboard, paste a Forge API token, and connect an MCP client in under ~10 minutes.
- Agents can: browse orgs/servers/sites; deploy and inspect deployments; read site logs; manage queue workers (Forge "background processes"); restart nginx / reload or restart php-fpm; view and edit site `.env`; run site commands.
- **Locked down by default.** A fresh install exposes read-only tools only. Every action is an explicit opt-in.
- Permissions can be granted globally and narrowed/widened **per server and per site** (e.g., "deploy allowed on staging site, not production").
- **Permissions belong to each MCP access token**, so different agents (e.g. laptop vs CI) can have different powers.
- **Secrets are scrubbed** from everything returned to an agent (logs, deploy output, command output), except where the user explicitly enabled reading `.env`.
- Every tool call is recorded in an audit log visible in the dashboard.
- MCP clients authenticate with dashboard-issued access tokens (v1). OAuth comes in phase 2.

**Non-goals (v1)**
- Creating/deleting/provisioning servers or sites; databases, SSL, DNS, firewall, SSH keys, backups, PHP version installs, server reboot. (Not exposed at all.)
- Multiple users / teams (phase 3 — but the data model must not block it).
- OAuth for MCP clients (phase 2).
- A hosted/SaaS version. Each person runs their own install.
- stdio/local MCP transport. HTTP only.

**Anti-goals — failure even if it "works"**
- An agent performs an action the user did not enable, or on a server/site the user did not allow.
- Secrets (Forge token, `.env` contents) leak into the audit log, application logs, or agent responses the user didn't opt into.
- The dashboard is reachable and usable by someone other than the owner (open registration, no auth on MCP endpoint).
- Installation is so fiddly that people give up.

## 3. Decided by the user

- Stack: **Laravel + `laravel/mcp`**, dashboard in **Livewire using Laravel's official Livewire starter kit**.
- Delivery: open source, self-hosted, configured through a **web dashboard** (not just config files).
- **Start locked down; users opt in to risky actions.**
- **Single user for v1; plan for teams later.**
- **Per-server and per-site restrictions.**
- MCP client auth: **access tokens first, then OAuth.**
- **Permissions are per MCP access token**, not per user.
- **Scrub secrets** from output returned to agents.
- v1 capabilities: **deploying, logs, env editing, queue workers**, plus restarting nginx/php-fpm and running site commands (the original motivation).

## 4. Platform facts this design relies on (researched 2026-11-03)

**Forge API (new, org-scoped).** Base `https://forge.laravel.com/api`, Bearer token, JSON:API responses, cursor pagination (`page[size]`, `page[cursor]`), **60 req/min per user** (separate limit for commands). All paths are `/orgs/{org}/...`; server and site ids are integers. Personal tokens have **selectable scopes**. The old v1 API is deprecated. OpenAPI spec: `https://forge.laravel.com/api/docs.openapi`. Official SDK `laravel/forge-sdk` **v4.x** targets this API.

Relevant endpoints (`O` = `/orgs/{org}`, `S` = `O/servers/{server}`):

| Need | Endpoint | Mode | Forge scope |
|---|---|---|---|
| Orgs | `GET /orgs` | sync | organization:view |
| Servers | `GET O/servers`, `GET S` | sync | server:view |
| Sites | `GET S/sites`, `GET O/sites/{site}` | sync | server:view |
| Deploy | `POST S/sites/{site}/deployments` | async (202) | site:manage-deploys |
| Deploy status / history / log | `GET S/sites/{site}/deployments/status`, `.../deployments`, `.../deployments/{id}/log` | sync | site:manage-deploys / server:view |
| Site logs | `GET S/sites/{site}/logs/{application\|nginx-access\|nginx-error}` | sync | server:manage-logs |
| Env read / write | `GET` / `PUT S/sites/{site}/environment` | sync / async | site:manage-environment |
| Nginx | `POST S/services/nginx/actions {action: reboot}` | async | server:manage-services |
| PHP-FPM | `POST S/services/php/actions {action: reboot\|reload, version}` | async | server:manage-services |
| Background processes (queue workers) | `GET/POST S/background-processes`, `GET/PUT/DELETE .../{id}`, `GET .../{id}/log`, `POST .../{id}/actions {restart\|stop\|start}` | list/get sync, rest async | server:create-daemons / server:delete-daemons |
| Site commands | `POST S/sites/{site}/commands {command}`, `GET .../commands/{id}`, `GET .../commands/{id}/output` | run async | site:manage-commands |

*(Scope column is best-effort from the spec; the builder must confirm each against the OpenAPI spec.)*

**`laravel/mcp` v1.1.x.** Requires Laravel 12.41+/13, PHP 8.2+. Servers registered in `routes/ai.php` via `Mcp::web(...)` (streamable HTTP). Tools are classes with a JSON schema, `handle()`, annotations (`#[IsReadOnly]`, `#[IsDestructive]`, `#[IsIdempotent]`, `#[IsOpenWorld]`), and **`shouldRegister(Request)`** to hide tools per user. Auth via Sanctum (`auth:sanctum`) or Passport OAuth 2.1 (`Mcp::oauthRoutes()`). Default protocol is stateless (no sessions). **No elicitation (server-initiated "are you sure?") in any release.** Testing helpers and `mcp:inspector` included.

## 5. Architecture

A standard Laravel 13 app:

- **Dashboard** (Livewire starter kit, Fortify auth with 2FA available): setup wizard, Forge connection, permissions, MCP access tokens, audit log.
- **MCP server** `App\Mcp\Servers\ForgeServer` mounted at `POST /mcp` behind `auth:sanctum` (v1). Tools live in `App\Mcp\Tools\...`.
- **Forge client layer** — a thin internal service (`ForgeClient`) wrapping `laravel/forge-sdk` v4, falling back to Laravel's HTTP client for anything the SDK lacks. All Forge access goes through it: one place for auth, rate-limit handling, error mapping, short-lived caching, and redaction.
- **Permission engine** — one service answering `can(token, capability, org, server?, site?)`. Every tool consults it, using the MCP access token that authenticated the request.
- **Secret scrubber** — one service every tool's text output passes through before it is returned (see §9a).
- **Audit logger** — records every tool invocation (allowed or denied).
- Database: whatever Laravel defaults to; **SQLite must work** so installs stay simple.

## 6. Setup & dashboard

1. **First run:** if no user exists, `/setup` lets the visitor create the owner account. Afterwards setup and registration are permanently closed. *(My call; the builder may also offer an equivalent artisan command.)*
2. **Forge connection:** paste a Forge API token. The app validates it (`GET /me`, `GET /orgs`), stores it **encrypted** (Laravel encrypted cast, APP_KEY), and never displays it again (show last 4 chars). The page explains which Forge token scopes each capability needs and recommends creating a token with only those.
3. **Organizations:** list orgs the token can see; the user picks which are exposed to agents. *(My call: if exactly one org exists it is pre-selected; otherwise none are until the user picks.)*
4. **Permissions** (see §7).
5. **MCP access tokens:** create named tokens (e.g. "Claude Code – laptop"), shown once, with last-used time and revoke. Each token has its own permissions (§7). A new token starts with the read-only defaults; the create form also offers "copy permissions from another token" *(my call)*. The token list shows a one-line summary of each token's powers (e.g. "read-only", "deploy on 2 sites"). Page shows copy-paste config snippets for Claude Code (`claude mcp add --transport http ...` with Bearer header) and a generic JSON config.
6. **Audit log:** filterable list of tool calls.

## 7. Permission model

**Capabilities** (each maps to one or more MCP tools):

| Capability | Tier | Default |
|---|---|---|
| `view` — list orgs/servers/sites, deployment status/history | Read | **On** |
| `view_logs` — deployment logs, site logs, background-process logs, command output | Read | **On** |
| `deploy` — trigger deployment | Action | Off |
| `restart_services` — restart nginx, reload/restart php-fpm | Action | Off |
| `manage_workers` — restart/stop/start existing background processes | Action | Off |
| `view_env` — read `.env` (exposes secrets to the agent) | **Risky** | Off |
| `edit_env` — change `.env` values | **Risky** | Off |
| `run_commands` — run arbitrary site commands | **Risky** | Off |
| `configure_workers` — create/update/delete background processes | **Risky** | Off |

*(Tier assignments and the decision that log reading is on by default are my calls. Logs can contain sensitive data; the dashboard should say so next to the toggle.)*

**Per token.** Every permission rule belongs to one MCP access token. Two tokens never share rules; editing one token's permissions never affects another.

**Scopes.** A permission rule is `(token, capability, scope, allowed: bool)` where scope is one of: global, org, server, site. Resolution: the **most specific matching rule wins** (site > server > org > global); no rule anywhere = denied (the read defaults are seeded as global "allowed" rules when a token is created). This supports both "allowed everywhere except prod" and "denied everywhere except staging".

**Server-level vs site-level capabilities** *(my call — chosen to fail safe)*:
- `restart_services` acts on a whole server, so it can only be set at global/org/server scope. The UI does not offer a per-site toggle for it. A site-level allow can never authorise a server-wide restart.
- `manage_workers` / `configure_workers`: a background process with a `site_id` is governed by that site's rules (falling back up the chain); a process without a `site_id` is governed by server-level rules only. Creating a worker for a site requires the capability on that site.
- All other capabilities can be set at every scope.

**Capabilities are independent** *(my call)*: none implies another (e.g., `edit_env` doesn't imply `view_env`; `deploy` doesn't require `view`). An action tool always returns its own result (`deploy_site` returns status and a log tail, `run_site_command` returns its output), because that output is part of the action.

**Denying `view` hides things** *(my call)*: list tools filter out any server or site where `view` resolves to denied, so a user can make "prod" invisible to agents. Filtering happens after fetching (from cache) and the tools return what remains; pagination is handled internally by the client layer fetching all pages (accounts are small enough at 30/page).

**Dashboard UI:** per token, a global capability grid, plus a server/site browser (fetched from Forge) where any capability can be overridden for a server or site. Risky capabilities show a clear warning when enabled.

**Tool visibility:** a tool is registered (`shouldRegister`) if its capability is allowed **anywhere** for the requesting token; otherwise the agent never sees it. Per call, the tool checks the specific target and returns a clear MCP error (e.g. "deploy is not permitted on site 123 (prod.example.com). Enable it in the Forge MCP dashboard."). Denied calls are audited.

**Data model note for teams later:** permission rules belong to a token; tokens, Forge connections and audit entries belong to a `user_id` now. Structure them so a team owner can be added without rewriting the engine (builder decides exact shape).

## 8. MCP tools (v1)

Naming: `snake_case`. Every tool that targets a resource takes `organization` (slug; optional when exactly one org is exposed), `server_id`, and where relevant `site_id`. List tools return compact summaries (id, name, key status), not raw JSON:API payloads, to save agent context. Long log/output text is truncated to the tail (default last ~200 lines, `lines` argument up to a cap) with a note when truncated.

**Read (`view`, `view_logs`)** — `#[IsReadOnly]`
- `list_organizations`, `list_servers`, `get_server`, `list_sites` (by server, or org-wide), `get_site`
- `get_deployment_status`, `list_deployments`, `get_deployment_log`
- `get_site_log` (`type`: application | nginx-access | nginx-error)
- `list_workers` (background processes, filterable by site), `get_worker_log`
- `get_command_result` (status, exit code, output for a previously run command)

**Actions**
- `deploy_site` (`deploy`) — triggers deploy, then polls status for up to ~20s; returns either the final result or "still deploying, deployment id X — call get_deployment_status".
- `restart_nginx`; `restart_php_fpm` (`action`: reload | restart; PHP `version` auto-detected from the site/server when omitted) (`restart_services`)
- `restart_worker`, `stop_worker`, `start_worker` (`manage_workers`)

**Risky** — `#[IsDestructive]` (action tools above also carry `#[IsDestructive]` where they interrupt service)
- `get_site_env` (`view_env`)
- `set_site_env_values` (`edit_env`) — takes a map of key → value and/or a list of keys to remove. The server fetches the current `.env`, applies the changes line-by-line preserving comments/order, and PUTs the result. **It never returns the file contents**, so `edit_env` does not imply `view_env`. Returns which keys were added/changed/removed (names only).
- `run_site_command` (`run_commands`) — runs, polls for up to ~20s, returns status/exit code/output tail, or the command id to check later.
- `create_worker`, `update_worker`, `delete_worker` (`configure_workers`)

**Confirmation:** since `laravel/mcp` has no elicitation, v1 relies on (a) the opt-in permission model, (b) `#[IsDestructive]` annotations so clients can prompt, (c) the audit log. We **do not** add a `confirm: true` argument — the agent would just set it, so it adds no safety. When `laravel/mcp` ships elicitation, add an optional per-capability "ask me first" setting (phase 2+).

## 9. Errors, limits, async

- Map Forge errors to clear agent-facing messages: 401 (token invalid/expired → "reconnect in dashboard"), 403 (Forge token lacks scope X), 404, 422 (validation detail), 429 (rate limited, retry after N s).
- Respect the 60/min limit: cache list endpoints (orgs, servers, sites) briefly (~60s), serve the permission UI's server/site browser from cache, never retry in a tight loop.
- Async operations: poll with backoff within a single request for at most ~20s, keeping total request time well under typical php-fpm/nginx timeouts (60s).

## 9a. Secret scrubbing

All text a tool returns to an agent — site logs, deployment logs/output, worker logs, command output, Forge error messages — passes through the scrubber. **Only `get_site_env` is exempt**, because the user explicitly opted in to `view_env` for that site.

What gets masked (replaced with `[REDACTED:<KEY_NAME>]` when the source key is known, else `[REDACTED]`):
1. **Known `.env` values** for the site the output belongs to. For output not tied to a site (a server-level worker), the values of every exposed site on that server *(my call)*.
2. **The Forge API token** and any MCP access token.
3. **Common secret patterns**: private key blocks, JWTs, URLs with embedded credentials, `KEY=value` / `"password": "..."`-style assignments where the key name looks secret (`*_KEY`, `*_SECRET`, `*_TOKEN`, `*PASSWORD*`), and well-known token formats (AWS access keys, Stripe `sk_`/`rk_`, GitHub `ghp_`/`github_pat_`, Slack `xox*`, OpenAI/Anthropic-style `sk-` keys).

Rules to avoid masking everything *(my calls; tunable constants)*: skip `.env` values shorter than 8 characters and obviously non-secret values (`true`, `false`, `null`, `local`, `production`, pure numbers, `APP_URL`/`APP_NAME`/`*_HOST`/`*_PORT`/`*_DRIVER`/`*_CONNECTION` keys). Longest values are matched first.

Fetching `.env` for matching:
- Needs the Forge token's `site:manage-environment` scope **even when `view_env` is off**. The dashboard's Forge-connection page says so.
- Values are cached **encrypted** (Laravel `Crypt`) for ~10 minutes per site, and the cache entry is dropped whenever `set_site_env_values` runs.
- If the `.env` can't be fetched (missing scope, error), the scrubber falls back to patterns only and the tool output ends with a note: "Secret scrubbing limited: could not read .env for site X." The dashboard shows the same warning.

The scrubber is pure (input text + known secrets → output text) and gets its own thorough test suite, including tests that it does not mangle ordinary log lines.

## 10. Audit log

Each entry: timestamp, MCP token name, tool, org/server/site, outcome (success / denied / Forge error), short result summary, duration. Arguments are stored **with secrets redacted**: `.env` values never stored (keys only); command text is stored, since it's the point of auditing *(my call)*. Retention: 90 days by default, pruned by a scheduled job (configurable). Install docs must say the scheduler needs to run.

## 11. Security

- Forge token encrypted at rest; never logged; never returned by any tool or page.
- MCP endpoint requires a valid Sanctum token; tokens are hashed, revocable, and limited to MCP use (cannot be used for the dashboard).
- Dashboard behind login; 2FA available via starter kit; registration closed after setup.
- HTTPS assumed (installed behind Forge-managed SSL); docs call it out.
- Rate-limit the MCP endpoint and login.
- Redaction applied centrally (secret scrubber §9a, audit redaction §10), not per tool. Audit result summaries are scrubbed too.

## 12. Installation & project hygiene

- Primary install path: create a site on Forge pointing at the GitHub repo (or a fork), set env, deploy, visit `/setup`. README walks through it.
- Also documented: generic `git clone` + `composer install` on any PHP 8.3+ host.
- MIT license *(my call)*. README, CONTRIBUTING, `.env.example`, GitHub Actions CI (Pest, Pint, Larastan).
- Tests: Pest; Forge API mocked with `Http::fake` (or SDK fakes); MCP tools tested with `laravel/mcp` test helpers; permission resolution exhaustively unit-tested. No test hits the real Forge API.

## 13. Phases

1. **v1 (this design):** everything above.
2. **Phase 2:** OAuth 2.1 for MCP clients via Passport (`Mcp::oauthRoutes()`), so Claude.ai custom connectors work; per-capability "ask first" once elicitation exists.
3. **Phase 3:** teams — multiple users, invitations, per-member permissions and tokens; possibly multiple Forge connections.

## 14. Left to the builder

- Exact class/table names, Livewire component structure, UI layout within the starter kit's look.
- Whether to use `laravel/forge-sdk` or the HTTP client directly, call by call.
- Polling intervals, cache TTLs, truncation sizes (within the guidance above).
- Tool descriptions/wording (written for an LLM reader: when to use, what it does, which args).

## 15. Unverified — confirm during build

- Whether the `POST .../commands` 202 response includes the new command id (fallback: list commands, take the newest).
- How php-fpm `version` should be determined for a site (site PHP version attribute vs server default).
- Exact Forge scope required per endpoint (check OpenAPI `security` per operation).
- Whether Claude Code treats `#[IsDestructive]` tools differently from others.
