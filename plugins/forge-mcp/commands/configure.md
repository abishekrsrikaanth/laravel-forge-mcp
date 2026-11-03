---
description: Connect this project to Forge MCP and choose its default Forge organization, server and site
argument-hint: "[site name or id]"
allowed-tools: mcp__forge-mcp__list_organizations mcp__forge-mcp__list_servers mcp__forge-mcp__list_sites mcp__forge-mcp__get_defaults mcp__forge-mcp__set_defaults Read Edit Write
---

Set up Forge MCP for this project: make sure the `forge-mcp` MCP server is configured, then store a default Forge organization, server and (optionally) site so that later Forge requests can leave them out.

Site hint from the user (may be empty): $ARGUMENTS

Follow these steps in order. Ask before you write anything, and keep the user informed in short sentences.

## Rules

- Never write an access token into any file, and never ask the user to paste one into the chat. Tokens only ever come from the `FORGE_MCP_TOKEN` environment variable, referenced as `${FORGE_MCP_TOKEN}`.
- Never print the value of an existing `Authorization` header you find in a file. Leave it exactly as it is.
- When you edit `.mcp.json`, merge: keep every other server and every other key. Change only the `forge-mcp` entry.
- Write `X-Forge-Organization`, `X-Forge-Server` and `X-Forge-Site` only with literal values (for example `"123"`), never as `${...}` variables. If a referenced environment variable isn't set, Claude Code either fails to load the server or sends an empty value, depending on version. An empty value makes Forge MCP answer every call with `Invalid X-Forge-… header.` `FORGE_MCP_URL` and `FORGE_MCP_TOKEN` must be set before Claude Code starts.
- Use only ids returned by the Forge MCP tools. Never guess a slug or an id.

## 1. Check that the Forge MCP server is available

If the `mcp__forge-mcp__*` tools are not available in this session, the server is not configured or not connected yet. Then:

1. Read `.mcp.json` in the project root, if it exists. If it cannot be parsed as JSON, stop and tell the user which file to fix.
2. Explain the two environment variables the configuration uses:
   - `FORGE_MCP_URL`: the base URL of the Forge MCP installation, without `/mcp`, for example `https://forge-mcp.example.com`.
   - `FORGE_MCP_TOKEN`: an access token from the **Access tokens** page of the Forge MCP dashboard.
3. With the user's confirmation, add (or update) the `forge-mcp` entry under `mcpServers` in `.mcp.json`, creating the file if needed:

   ```json
   {
     "mcpServers": {
       "forge-mcp": {
         "type": "http",
         "url": "${FORGE_MCP_URL}/mcp",
         "headers": {
           "Authorization": "Bearer ${FORGE_MCP_TOKEN}"
         }
       }
     }
   }
   ```

   Keep every other server and key in the file. If a `forge-mcp` entry already exists, keep its existing `Authorization` header and any `X-Forge-*` headers.
4. Tell the user to export both variables in the shell they start Claude Code from (for example in their shell profile), restart Claude Code so it sees them, approve the `forge-mcp` project server when asked (or open `/mcp`, select `forge-mcp` and choose Reconnect), and then run `/forge-mcp:configure` again.

Stop here. Do not continue to the next steps in this run.

## 2. Choose the organization, server and site

1. Call `mcp__forge-mcp__get_defaults` and tell the user which defaults are active now, if any.
2. Call `mcp__forge-mcp__list_organizations`. If there is exactly one organization, use it. Otherwise ask the user to pick one by name.
3. If the site hint above is not empty, call `mcp__forge-mcp__list_sites` for that organization and look for a site whose id equals the hint, or whose name matches it (exact match first, then a case-insensitive partial match). If exactly one site matches, propose it together with its server. If several match, let the user pick from them. If none match, say so and continue with the next step.
4. Otherwise, call `mcp__forge-mcp__list_servers` and let the user pick a server by name. Then call `mcp__forge-mcp__list_sites` with that `server_id` and let the user pick a site, or choose no default site (a server-only default).
5. Confirm the choice with the user, showing names and ids: organization, server and site.

## 3. Choose where to save the defaults

Ask the user where to save them:

- **This project** (the default): the defaults are stored as headers in this project's `.mcp.json`, so they apply to everyone who uses this repository, with their own token. The file holds no secret and can be committed.
- **This access token**: the defaults are stored in Forge MCP for the token in use, and apply in every project that uses this token without project headers.

Project headers always win: if any `X-Forge-*` header is set, the token's stored defaults are ignored entirely.

### This project

1. Read `.mcp.json` in the project root. If there is no `forge-mcp` entry in it, the server is configured somewhere else (for example with `claude mcp add`, in the user or local scope), and a local entry with the same name takes precedence over the project file. Explain this, and offer either to add a project entry as shown in step 1 (the user then removes the other entry with `claude mcp remove forge-mcp` and sets the environment variables), or to save the defaults on the access token instead.
2. In `mcpServers.forge-mcp.headers`, set:
   - `"X-Forge-Organization"`: the organization slug,
   - `"X-Forge-Server"`: the server id, as a string,
   - `"X-Forge-Site"`: the site id, as a string. If no site was chosen, remove any existing `X-Forge-Site` header instead.

   Replace any previous `X-Forge-*` values. Keep the `Authorization` header as it is; if there is none, add `"Authorization": "Bearer ${FORGE_MCP_TOKEN}"`. Keep the file's other servers and keys, and keep it valid JSON with two-space indentation.
3. Show the user the resulting `forge-mcp` entry, with the `Authorization` value replaced by `…`.

### This access token

Call `mcp__forge-mcp__set_defaults` with `organization`, `server_id` and, if one was chosen, `site_id`. Report the result. If the project's `.mcp.json` already has `X-Forge-*` headers for `forge-mcp`, warn that they override the token defaults in this project.

## 4. Reconnect and verify

- **This project:** tell the user to open `/mcp`, select `forge-mcp` and choose Reconnect (or run `/mcp reconnect all`), so the new headers are sent. When they say it is done, call `mcp__forge-mcp__get_defaults` and check that it reports the chosen server and site `(from project headers)`.
- **This access token:** call `mcp__forge-mcp__get_defaults` right away and check that it reports them `(from this access token)`.

If `get_defaults` answers `Invalid X-Forge-… header.`, one of the headers is empty or malformed: check that each `X-Forge-*` value in `.mcp.json` is a literal, that the organization is a slug and the server and site are positive numbers, and that `X-Forge-Site` is only set together with `X-Forge-Server`.

Finish with one line telling the user that Forge requests in this project can now leave out the server and site, and that `/forge-mcp:diagnose` diagnoses the latest deployment of the default site.
