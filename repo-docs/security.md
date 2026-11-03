# Security

Forge MCP holds a Forge API token and exposes Forge operations to AI agents. It is built to limit what a misbehaving, confused or compromised agent can do, and to leave a record of what it did. This page explains the protections and their limits. To report a vulnerability, see [SECURITY.md](../SECURITY.md).

## Threat model

**What Forge MCP protects against**

- An agent doing more than you allowed: every call is checked against the token's [permissions](permissions.md) on the exact organization, server and site, and tools the token cannot use are not even listed.
- An agent leaking secrets into its context, chat transcript or logs: tool output is scrubbed of known secrets and common token formats.
- An agent (or anyone holding an access token) obtaining your Forge API token: it is never returned by any tool, and is removed from all output, error messages and audit entries.
- Not knowing what happened: every call that reaches the permission check is written to the audit log, including denied and failed calls.

**Out of scope**

- A compromised host or database running Forge MCP, or anyone with access to its `APP_KEY` and `.env`.
- A compromised Forge account or Forge API token.
- An agent you deliberately granted risky capabilities such as `run_commands`, `configure_workers`, `edit_env` or `configure_deployments`. These allow arbitrary code execution as the `forge` user by design (see [the shared `forge` user](permissions.md#the-shared-forge-user)).
- Weak owner passwords, or serving Forge MCP over plain HTTP.
- Secrets the scrubber has never seen, for example a secret that is not in a site's `.env` and does not match a known pattern.

## Token storage

- **Forge API token:** stored encrypted with the app's `APP_KEY`, never shown again after you paste it (the dashboard shows only its last four characters), and never included in any tool result.
- **MCP access tokens:** stored hashed. The plain-text token is shown once, right after creation. Each carries the `mcp` ability and can be revoked at any time; revoking also deletes its permission rules. Tokens do not expire on their own.
- **Secrets cache:** the `.env` values used for scrubbing are cached per site, encrypted, for `FORGE_MCP_SECRETS_CACHE_TTL` seconds (600 by default).

## The /mcp endpoint

- Bearer tokens only (`Authorization: Bearer <token>`). A logged-in dashboard session does not authenticate MCP requests.
- Requests without a valid token get HTTP 401.
- 120 requests per minute per token; beyond that, HTTP 429.
- Unexpected server errors return a generic message (`Unexpected error while running <tool>. Check the Forge MCP server logs.`) and are logged with secrets scrubbed, so exception details never reach the agent.

## The setup window

Until the owner account exists, `/setup` lets anyone who can reach the app create it. After that it returns 404 and there is no sign-up page. Complete setup right after the first deploy, or restrict access to the site until you have.

## Secret scrubbing

Every tool result passes through the scrubber before it reaches the agent (the only exception is `get_site_env`, below). Scrubbing has two passes.

### 1. Known values

Forge MCP reads the `.env` of the site a call is about (for a server-level call, the `.env` of every site on that server) and replaces each value with `[REDACTED:KEY]`, for example `[REDACTED:DB_PASSWORD]`. The Forge API token is replaced the same way, as `[REDACTED:FORGE_API_TOKEN]`.

Each value is also matched in the forms it takes in logs:

- JSON-escaped (with and without escaped slashes and Unicode),
- URL-encoded,
- with single quotes escaped SQL-style (`''`) or PHP-style (`\'`),
- as PHP prints a string argument in a stack trace: escaped, and truncated with `...` (PHP truncates trace arguments to 15 characters by default),
- for `base64:` values such as `APP_KEY`, the base64 payload on its own.

Values that are not treated as secrets:

- values shorter than `FORGE_MCP_SCRUB_MIN_LENGTH` (8 by default),
- `true`, `false`, `null`, `local`, `production`, `staging`, `testing`, and plain numbers,
- values of `APP_URL`, `APP_NAME`, `APP_ENV`, `APP_DEBUG` and keys ending in `_HOST`, `_PORT`, `_DRIVER` or `_CONNECTION`,
- references such as `${OTHER_KEY}`.

After `set_site_env_values` changes a value, both the old and the new value stay known, so a rotated-out secret stays masked.

### 2. Patterns

Independently of any `.env`, these are replaced with `[REDACTED]`:

- PEM private key blocks (`-----BEGIN … PRIVATE KEY-----`),
- JSON Web Tokens,
- AWS access key ids,
- Stripe live secret and restricted keys,
- GitHub tokens (`ghp_…`, `github_pat_…`),
- Slack tokens (`xoxb-…` and similar),
- `sk-…` API keys,
- Laravel Sanctum plain-text tokens (`<id>|<40+ characters>`),
- credentials in URLs (`scheme://user:password@host` keeps the user, masks the password),
- values of keys ending in `KEY`, `SECRET`, `TOKEN`, `PASSWORD` or `PASS`, in `KEY=value`, `Key: value` and JSON `"key": "value"` form (the key stays visible; placeholders such as `null`, `***` or `${VAR}` are left alone),
- `--password=…` / `--password …` (also `--db-password` and similar) command-line flags,
- `-p<password>` in `mysql`, `mysqldump`, `mariadb` and related commands.

If a pattern cannot be evaluated on some text (for example because it is pathologically long), the affected lines are replaced with `[REDACTED: unscannable]` rather than passed through.

### "Secret scrubbing limited" warnings

If Forge MCP cannot read a `.env` it needs (for example because Forge is unreachable or the token lacks `server:view`), or a server has more sites than it reads in one call (at most 10 uncached `.env` files per call), the output ends with a line such as:

```text
Warning: Secret scrubbing limited: could not read .env for site 123.
```

Pattern-based scrubbing still applies. For servers with many sites, retrying shortly fills the cache and the warning disappears.

### `get_site_env`

`get_site_env` (capability `view_env`) returns the `.env` file as it is: its purpose is to show the values. Only the Forge API token is removed from it. `view_env` is a risky capability and is off by default; grant it only where you accept that the agent, its transcript and its provider see those secrets.

## Audit log

Each tool call that reaches the permission check writes one entry:

| Field | Content |
|---|---|
| Time | When the call happened. |
| Token | The access token and its name (the name is kept after the token is revoked). |
| Tool | The tool name. |
| Target | Organization, server id and site id, when known. |
| Outcome | `success`, `denied` or `error`. |
| Summary | A short description, such as `Deployed site 34: finished` or the denial message (scrubbed, at most 2,000 characters). |
| Arguments | The arguments the agent passed, scrubbed and capped. |
| Duration | Milliseconds. |

Tool **output** is not stored.

How arguments are protected:

- Every string argument is scrubbed with the Forge token, the pattern rules and the target's known secrets (on a failed or denied call, only the site secrets already in the cache).
- `set_site_env_values` stores only the key names of `set` (every value becomes `[REDACTED]`) and `remove`; `enable_maintenance_mode` stores its bypass `secret` as `[REDACTED]`. Both keep only their known arguments.
- Each string is cut to 4,000 characters (`…[truncated]`); if all arguments together exceed 16 KB of JSON, they are replaced by `{"_truncated": true}` plus the organization and id arguments.

Not recorded: calls rejected before the permission check because arguments were malformed or missing, and calls without a valid access token or Forge connection.

Entries are kept for `FORGE_MCP_AUDIT_RETENTION_DAYS` (90 by default) and pruned daily by the scheduler. Only the owner can see them, in the dashboard's **Audit log**.

## Recommendations

- Serve Forge MCP over HTTPS only, and complete `/setup` immediately.
- Enable two-factor authentication or a passkey for the owner account (**Settings**).
- Give the Forge API token only the scopes you need ([Forge API token](forge-api-token.md)).
- Create one access token per agent or device, keep new tokens read-only, and grant risky capabilities per server.
- Revoke tokens you no longer use, and review the audit log now and then.

## Reporting a vulnerability

Please report vulnerabilities privately through GitHub Security Advisories, as described in [SECURITY.md](../SECURITY.md). Do not open a public issue.
