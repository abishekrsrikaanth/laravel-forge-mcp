# Security policy

## Reporting a vulnerability

Please report vulnerabilities privately through GitHub Security Advisories: open the repository's **Security** tab and choose **Report a vulnerability**. Do not open a public issue for a security problem.

Include what you found, how to reproduce it, and the impact you expect. You will get an acknowledgement within a few days, and we will keep you updated until a fix is released.

## Supported versions

Only the latest released version receives security fixes.

## Threat model

Forge MCP holds a Forge API token and exposes Forge operations to AI agents, so it is built to limit what a misbehaving or compromised agent can do.

**Protected**

- The Forge API token is stored encrypted and is never returned by any tool.
- MCP access tokens are hashed at rest, shown once, and can be revoked at any time. Each token has its own permissions, locked down to read-only by default, with overrides per organization, server and site.
- Server-wide actions (service restarts, reboot) cannot be granted for a single site.
- Tool output is scrubbed of known secrets; only the explicit `view_env` capability returns raw `.env` values.
- Every permission-checked tool call (allowed, denied or failed) is written to the audit log.
- Setup is available once, until the owner account exists.

**Out of scope**

- A compromised host or database running Forge MCP, or anyone with access to its `APP_KEY` and `.env`.
- A compromised Forge account or Forge API token itself.
- An agent that has been deliberately granted risky capabilities such as `run_commands`; these allow arbitrary code execution by design.
- Weak owner passwords, or serving Forge MCP over plain HTTP.
- Secrets that were never seen by the scrubber (for example a secret an agent writes into a command's output that is not in the `.env`).
