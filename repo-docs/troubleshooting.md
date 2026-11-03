# Troubleshooting

## A tool is not visible to the agent

A tool is listed only when the access token's capability is allowed somewhere the token can reach. Check, in order:

1. **Forge is connected** (dashboard home or **Forge connection**).
2. **At least one organization is exposed** under **Exposed organizations**. With none, the agent only sees `list_organizations`, which tells it so.
3. **The token has the capability.** Open **Access tokens → Permissions** for the token. The tool's capability (see the [tool reference](tools.md)) needs a global allow or an allow override in an exposed organization. Remember capabilities are independent: `deploy` does not include `view_logs`.
4. **The client has refreshed its tool list.** Many clients read the tool list only when they connect; restart the session after changing permissions.
5. **`diagnose_failed_deploy` is missing:** it needs both `view` and `view_logs`, and a client that supports MCP prompts.

## Defaults and `X-Forge-*` headers

- **`Invalid X-Forge-Organization header.`** (or `X-Forge-Server`, `X-Forge-Site`) on every call: the header is empty or malformed. A common cause is a `${...}` variable in `.mcp.json` that is not set: depending on version, Claude Code then either fails to load the server or sends an empty value. Use literal values (see [the empty header trap](project-defaults.md#the-empty-header-trap)) and reconnect the server.
- **`X-Forge-Site requires X-Forge-Server.`** Add the server header or remove the site header.
- **`server_id is required (no default server is configured for this project or token).`** Pass `server_id`, or configure a default ([Project defaults](project-defaults.md)).
- **The token's defaults are ignored.** Project headers replace them as a bundle whenever any `X-Forge-*` header is sent. `get_defaults` shows which source is in effect.

## "… is not permitted on … for this access token"

The tool is visible, but the token is not allowed to use it on that particular organization, server or site. The message names the capability and the target, for example `Deploy is not permitted on site 34 on server 12 (acme)`. Add an allow at the right scope, or remove a deny that is more specific than your allow. See [How a permission is decided](permissions.md#how-a-permission-is-decided).

Common surprises:

- **Server-level operations** (`restart_services`, `reboot_server`, root workers, raw worker config) ignore site-level allows. For root workers and raw worker config, the message says the permission is needed on the server.
- **Workers** need the permission on every site the worker may belong to ([Workers and sites](permissions.md#workers-and-sites)).
- **Server-wide reads** (`list_server_events`, `get_server_event_output`, and scheduled jobs without `site_id`) need the capability on the server and on every site of it ([Server-wide reads](permissions.md#server-wide-reads-need-every-site)).
- **`Organization 'x' is not available to this MCP server.`** The organization is not exposed (or the slug is wrong). Expose it on **Forge connection**.

Agents are told not to retry denied calls and not to work around them; they should tell you which permission is missing.

## Errors from Forge

| Message | Meaning | Fix |
|---|---|---|
| `Forge rejected the API token (401). Reconnect in the Forge MCP dashboard.` | The Forge token was revoked or expired. The dashboard shows the error too. | Create a new Forge token and use **Replace token** on **Forge connection**. |
| `Forge denied <action> (403). The Forge API token may be missing a required scope.` | The Forge token lacks a scope this capability needs. | Check [the scope table](forge-api-token.md#scopes-per-capability) and replace the token with one that has the scope. |
| `Forge could not find the requested resource (404) while trying to <action>.` | Wrong id, or the resource belongs to another server or site. | Look the id up again with the list tools. |
| `Forge rejected the request to <action> (422): …` | Forge refused the input. | Read the message; correct the arguments. |
| `Forge rate limit reached (429). Retry in N seconds.` | Forge's API rate limit was hit. | Wait. Raising `FORGE_MCP_LIST_CACHE_TTL` reduces repeated list requests. |
| `Could not reach Forge while trying to <action>. Try again shortly.` | Network problem or timeout (15 seconds). | Retry; check the server's outbound connectivity. |

## Deployments and commands

**"Deployment N is still deploying. Call get_deployment_status to check progress."** `deploy_site` waits up to `FORGE_MCP_POLL_TIMEOUT` seconds (20 by default). Longer deployments keep running in Forge; the agent should poll `get_deployment_status` and then read `get_deployment_log`, not deploy again. If a site stays stuck in "deploying" with nothing running, `reset_deployment_state` (capability `deploy`) clears it.

**"Command N is still running. Call get_command_result with command_id N."** Same for `run_site_command`: call `get_command_result`.

**"Command queued, but its id could not be determined. Check the site's commands in Forge."** The command was sent to Forge, but Forge MCP could not tell which entry in the site's command history was the new one. Look in Forge before running it again, so it does not run twice.

## `.env` edits refused

`set_site_env_values` refuses rather than risk damaging the file:

| Message | Fix |
|---|---|
| ``Pass `set` and/or `remove` with at least one key.`` | Give at least one key. |
| `` `set` must be an object of KEY => string value. `` or `The value for KEY must be a string.` | Pass string values in an object, e.g. `{"APP_DEBUG": "false"}`. |
| `Invalid env key: …` | Keys must match `[A-Za-z_][A-Za-z0-9_]*`. |
| `A key cannot be both set and removed.` | Use one or the other per key. |
| `Could not read the current .env; refusing to overwrite it.` | Forge returned no `.env`; check the site in Forge. |
| `Another .env edit for this site is in progress; try again.` | Another edit held the site's lock for more than 10 seconds. Retry. |

Changes take effect on the next deployment or config reload of that site.

## "Warning: Secret scrubbing limited: …"

Forge MCP could not read a `.env` it uses for scrubbing, or (for server-level calls) there were more than 10 sites whose `.env` was not cached yet. Pattern-based scrubbing still ran, but values only known from that `.env` may not be masked. Make sure the Forge token has `server:view`; for large servers, retry shortly and the warning goes away as the cache fills. See [Security](security.md#secret-scrubbing-limited-warnings).

## The scheduler is not running

Symptom: audit log entries older than the retention period (90 days by default) are still there. Make sure `php artisan schedule:run` runs every minute (in Forge, as a scheduled job for the site). You can check with:

```bash
php artisan schedule:list
```

Nothing else depends on the scheduler.

## Rate limits

- **HTTP 429 from `/mcp`:** more than 120 requests in a minute with one access token. Wait a minute; if it keeps happening, check the agent is not stuck in a loop.
- **429 from Forge:** see [Errors from Forge](#errors-from-forge).

## Other problems

- **`Forge is not connected. Connect it in the Forge MCP dashboard.`** Connect a Forge token on **Forge connection**.
- **`Unexpected error while running <tool>. Check the Forge MCP server logs.`** Something failed inside Forge MCP. Details (with secrets scrubbed) are in `storage/logs`. If it looks like a bug, please open an issue, and for anything security-related follow [SECURITY.md](../SECURITY.md).
- **The client snippets show `http://localhost`.** Set `APP_URL` to your public URL and redeploy.
- **You lost the owner password.** Use **Forgot password** on the login page; it needs working mail settings (`MAIL_*`).
