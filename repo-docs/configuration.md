# Configuration

Forge MCP is configured through environment variables in `.env`. `.env.example` lists all of them. When the configuration is cached (`php artisan optimize` or `php artisan config:cache`, as the Forge deployment script does), run `php artisan config:cache` again or redeploy after changing a value.

## Application

| Variable | Recommended in production | Notes |
|---|---|---|
| `APP_NAME` | `"Forge MCP"` | Shown in the dashboard. |
| `APP_ENV` | `production` | In production, owner passwords must be at least 12 characters with mixed case, letters, numbers and symbols, and not found in known breaches. |
| `APP_DEBUG` | `false` | Never enable debug mode on a public install. |
| `APP_KEY` | generated once | Encrypts the stored Forge API token, cookies and the secrets cache. Changing it makes the stored Forge token unreadable (reconnect Forge afterwards). Laravel's `APP_PREVIOUS_KEYS` can be used to rotate it. |
| `APP_URL` | your `https://` URL | Used to build the MCP endpoint URL in the client snippets on the **Access tokens** page. |
| `LOG_CHANNEL`, `LOG_LEVEL` | `stack`, `info` or higher | Unexpected tool errors are logged (scrubbed) here. |

## Database

| Variable | Notes |
|---|---|
| `DB_CONNECTION` | `sqlite` (default), `mysql`, `mariadb` or `pgsql`. |
| `DB_DATABASE` | For SQLite, defaults to `database/database.sqlite`. |
| `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` | For MySQL, MariaDB or Postgres. |

The database holds the owner account, the encrypted Forge connection, access tokens (hashed), permission rules and the audit log.

## Cache and sessions

| Variable | Default | Notes |
|---|---|---|
| `CACHE_STORE` | `database` | Holds cached Forge list responses, the encrypted secrets used for scrubbing, and the lock that serialises `.env` edits. Use a store shared by all PHP workers that supports locks (`database`, `redis`, `file` on a single server). Do not use `array`. |
| `SESSION_DRIVER` | `database` | Dashboard sessions. |
| `SESSION_LIFETIME` | `120` | Minutes. |
| `QUEUE_CONNECTION` | `database` | Forge MCP does not queue any jobs; you do not need to run a worker. |
| `MAIL_*` | `log` | Only used for password reset and email verification mails. |

## Forge MCP tunables

These live in `config/forge-mcp.php`, and each can be overridden with an environment variable (they are listed, commented out, in `.env.example`). Empty or non-numeric values fall back to the default; values below the minimum are raised to it.

| Variable | Default | Minimum | What it sets |
|---|---|---|---|
| `FORGE_MCP_POLL_TIMEOUT` | `20` | `1` | Seconds `deploy_site` and `run_site_command` wait for a result before telling the agent to check back with `get_deployment_status` or `get_command_result`. Keep it below your PHP-FPM and proxy timeouts. |
| `FORGE_MCP_LIST_CACHE_TTL` | `60` | `0` | Seconds to cache Forge list responses (organizations, servers, sites). `0` disables caching but costs more Forge API requests. |
| `FORGE_MCP_SECRETS_CACHE_TTL` | `600` | `0` | Seconds to cache each site's (encrypted) `.env` values used for secret scrubbing. |
| `FORGE_MCP_LOG_TAIL_DEFAULT` | `200` | `1` | Lines returned by log tools when the agent does not pass `lines`. |
| `FORGE_MCP_LOG_TAIL_MAX` | `1000` | the default | Maximum lines any log tool returns. |
| `FORGE_MCP_AUDIT_RETENTION_DAYS` | `90` | `1` | Days to keep audit log entries. |
| `FORGE_MCP_SCRUB_MIN_LENGTH` | `8` | `4` | Shortest `.env` value that is masked in tool output. Shorter values are not treated as secrets. |

## Rate limits

- **MCP endpoint:** 120 requests per minute per access token. Requests beyond that get HTTP 429 until the minute is over.
- **Forge API:** Forge limits API requests too. When Forge answers 429, the tool returns `Forge rate limit reached (429). Retry in N seconds.` (or `Retry shortly.`). Caching (`FORGE_MCP_LIST_CACHE_TTL`, `FORGE_MCP_SECRETS_CACHE_TTL`) keeps the number of Forge requests down.

Requests to the Forge API time out after 15 seconds.

## Audit log retention

Entries older than `FORGE_MCP_AUDIT_RETENTION_DAYS` are deleted by a daily scheduled job (`model:prune`). This only happens if the scheduler runs (`php artisan schedule:run` every minute). To prune by hand:

```bash
php artisan model:prune --model="App\Models\AuditEntry"
```
