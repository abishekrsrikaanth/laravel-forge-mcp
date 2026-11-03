# Contributing

Local setup, the checks every pull request must pass, and commit conventions are in the root [CONTRIBUTING.md](../CONTRIBUTING.md). Security issues go through [SECURITY.md](../SECURITY.md). This page is a map of the code for contributors.

## Architecture

```text
POST /mcp  (routes/ai.php: auth:sanctum, abilities:mcp, throttle:mcp)
   │
   ▼
App\Mcp\Servers\ForgeServer          tool and prompt registry, server instructions
   │
   ▼
App\Mcp\Tools\<Tool> extends ForgeTool
   │   execute(): validate arguments, resolveOrganization(), then guarded(...)
   ▼
ForgeTool::guarded()
   ├─ ToolContext      user, access token, ForgeClient, ForgeConnection
   ├─ target           serverTarget() / siteTarget() / workerTarget() / serverWideTarget()
   ├─ PermissionEngine allows(token, capability, target)
   ├─ ForgeClient      the Forge API call(s)
   ├─ SecretScrubber   with SiteSecrets for the target
   └─ AuditLogger      one AuditEntry per call
```

| Class | Responsibility |
|---|---|
| `App\Forge\ForgeClient` | Every Forge API call (`https://forge.laravel.com/api`): pagination, flattening of JSON:API resources, a 15-second timeout, list caching (`list_cache_ttl`), and `ForgeException` with agent-safe messages for 401/403/404/422/429. Tools never make HTTP calls themselves. |
| `App\Forge\WorkerSiteResolver` | Infers the candidate sites of a background process from its directory. |
| `App\Permissions\Capability` | The twelve capabilities: tier, label, description, default, whether site scope is allowed, required Forge scopes. |
| `App\Permissions\PermissionEngine` | `allows()` (most specific rule wins, unexposed organizations denied, no rule means denied), `allowsAnywhere()` (tool visibility), `set()` and `summary()`. |
| `App\Permissions\Target` | Organization, server id, site id. Built only from ids Forge has confirmed. |
| `App\Security\SecretScrubber` | Known-value and pattern scrubbing; fails closed. |
| `App\Security\SiteSecrets` / `SecretSet` | Loads and caches (encrypted) the `.env` values of a site or server for scrubbing, with warnings when incomplete. |
| `App\Audit\AuditLogger` | Writes `AuditEntry` rows with scrubbed, capped arguments and summary. |
| `App\Mcp\AccessTokens` | Issues tokens with the `mcp` ability and default (or copied) rules. |

### The order inside `guarded()`

`ForgeTool::guarded(Request, Target|Closure $target, Closure $fn, bool $scrub = true)` is the safety spine. In order it:

1. Builds the context. Without a valid access token or a Forge connection it returns an error (not audited: there is no context).
2. Resolves the target (a closure is called with the context; this is where server and site ids are verified against Forge).
3. Checks the permission. A denial is audited as `denied` and returned.
4. Runs `$fn`, which returns the output text and may set `$ctx->summary`.
5. Maps errors: `ForgeException` (a 401 also records `last_error` on the connection), `ToolError` and `InvalidArgumentException` become audited `error` responses with the Forge token scrubbed; any other exception becomes a generic message plus a scrubbed log line; `ValidationException` is re-thrown so the MCP package reports it.
6. Scrubs the output with the Forge token plus the target's secrets (`scrub: false` removes only the Forge token, and is reserved for `get_site_env`).
7. Appends `Warning: …` lines for incomplete secret sets.
8. Clears `last_error`, audits `success` and returns the text.

`ForgeTool::handle()` wraps `execute()` so that a `ToolDenied` thrown outside `guarded()` (for example by `resolveOrganization()` for an unexposed organization) is audited as `denied`, and unexpected exceptions never leak.

## Adding a tool

1. **Pick the capability.** Reuse an existing one where it fits; reads go under `view` or `view_logs`. Adding a capability means updating `Capability` (tier, label, description, `forgeScopes()`, and `allowsSiteScope()` if it is server-wide) and the docs.
2. **Check `ForgeClient`.** Add a method there if the Forge endpoint is not covered yet; keep error messages free of request data.
3. **Create the tool** in `app/Mcp/Tools/`, extending `ForgeTool`:
   - set `$name`, add a `#[Description(...)]` written for agents, and annotate it `#[IsReadOnly]` or `#[IsDestructive]` as appropriate;
   - implement `capability()`, `schema()` and `execute()` (not `handle()`);
   - resolve the organization with `resolveOrganization()`;
   - build targets only with `serverTarget()` or `siteTarget()` (never from raw ids), `workerTarget()` for background processes, `serverWideTarget()` for server-wide reads that may contain any site's data, and `requireServerPermission()` when a site-scoped call affects the whole server;
   - put every Forge call inside `guarded()`, and use `OutputTail::tail()` for long text;
   - override `redactArguments()` if an argument can hold a secret.
4. **Register it** in `ForgeServer::$tools`.
5. **Test it** under `tests/Feature/Mcp/` with Pest, using the helpers in `tests/Helpers/Mcp.php` and Laravel's HTTP fake for Forge: allowed and denied paths, wrong server or site ids, unexposed organizations, scrubbing of the output, and the audit row. Update the tools list expectations in `McpEndpointTest` if needed.
6. **Document it** in the README tool table and in [the tool reference](tools.md).

Then run the checks from [CONTRIBUTING.md](../CONTRIBUTING.md#checks): `php artisan test`, `vendor/bin/pint --test` and `vendor/bin/phpstan analyse`.
