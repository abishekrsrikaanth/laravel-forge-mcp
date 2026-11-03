---
description: Diagnose the latest deployment of a Forge site (read-only)
argument-hint: "[site id]"
allowed-tools: mcp__forge-mcp__get_defaults mcp__forge-mcp__list_organizations mcp__forge-mcp__list_sites mcp__forge-mcp__get_deployment_status mcp__forge-mcp__list_deployments mcp__forge-mcp__get_deployment_log mcp__forge-mcp__get_site_log
---

Diagnose the most recent deployment of a Laravel Forge site through Forge MCP. This is read-only: never deploy, restart, edit or change anything, even if a fix looks obvious. Describe the fix and let the user decide.

Site id from the user (may be empty): $ARGUMENTS

If the `mcp__forge-mcp__*` tools are not available in this session, tell the user to run `/forge-mcp:configure` first and stop.

## 1. Find the site

1. Call `mcp__forge-mcp__get_defaults` and note the organization, server and site it reports.
2. If a site id was given above:
   - It must be a positive whole number. If it is not, ask the user for the site id (`mcp__forge-mcp__list_sites` lists them) and stop.
   - If the defaults name that site, use the default server and organization.
   - Otherwise call `mcp__forge-mcp__list_sites` (in the default organization, or the only one; if several organizations are available and none is a default, ask which one, using `mcp__forge-mcp__list_organizations`) and find the server that site is on. If it is not listed, say so and stop.
   - Pass `server_id` and `site_id` (and `organization` when known) explicitly in every call below.
3. If no site id was given, use the default site. If no default site is configured, tell the user to pass a site id (`/forge-mcp:diagnose 456`) or set a default with `/forge-mcp:configure`, and stop. With a default site, the calls below may leave out `organization`, `server_id` and `site_id`.

Tell the user in one line which site you are diagnosing.

## 2. Gather the facts

Make these calls in order:

1. `mcp__forge-mcp__get_deployment_status`.
2. `mcp__forge-mcp__list_deployments` with `limit` 3.
3. `mcp__forge-mcp__get_deployment_log` for the most recent **failed** deployment in that list. If none of them failed, say so and read the log of the most recent deployment instead only if it is still running or looks stuck.
4. `mcp__forge-mcp__get_site_log` with `type` `application` and `lines` 100.

If a call is denied (`… is not permitted on … for this access token.`), do not retry it and do not try to get the same information another way. Name the missing permission, say that the user can enable it for this token in the Forge MCP dashboard, and continue with the calls that work.

## 3. Report

- The site, and the status and time of the latest deployments.
- The most likely cause, in 3 to 5 bullets.
- The decisive log lines, quoted exactly. Secrets appear as `[REDACTED]` markers; do not try to recover them.
- A proposed fix, as steps the user can take. Do not carry them out.
