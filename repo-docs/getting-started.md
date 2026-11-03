# Getting started

This is the shortest path from nothing to an agent that can read your Forge account. It assumes you already use Laravel Forge and have a server to host Forge MCP on. For every detail and alternative, see [Installation](installation.md).

## 1. Deploy Forge MCP

1. In Forge, create a new site on one of your servers (for example `mcp.example.com`) and install it from the repository `abishekrsrikaanth/laravel-forge-mcp`.
2. In the site's environment (`.env`), set at least:

   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=base64:<generate with: php artisan key:generate --show>
   APP_URL=https://mcp.example.com
   DB_CONNECTION=sqlite
   ```

3. Replace the deployment script with the one in [Installation](installation.md#4-deployment-script).
4. Enable the scheduler and SSL (Let's Encrypt) for the site.
5. Deploy.

## 2. Claim the app at /setup, right away

Open `https://mcp.example.com/setup` straight after the first deploy and create the owner account. Setup works exactly once. **Until it is done, anyone who can reach the site can claim it**, so do not leave a fresh install unclaimed.

## 3. Connect Forge

1. In Forge, create an API token. For a read-only start, select the scopes `server:view`, `server:manage-logs`, `site:manage-deploys` and `server:create-daemons`. See [Forge API token](forge-api-token.md) for other recipes.
2. In the Forge MCP dashboard, open **Forge connection**, paste the token and choose **Connect to Forge**.
3. Under **Exposed organizations**, tick the organizations agents may reach and choose **Save**. If your token can see only one organization, it is selected for you.

## 4. Create an access token

1. Open **Access tokens**, give the token a name (for example `Laptop - Claude Code`) and choose **Create token**.
2. Copy the token now. It is shown once.

A new token is read-only: it can use `view` and `view_logs` and nothing else. You can grant more later under **Permissions** (see [Permissions](permissions.md)).

## 5. Connect Claude Code

```bash
claude mcp add --transport http forge https://mcp.example.com/mcp --header "Authorization: Bearer <token>"
```

The **Access tokens** page shows this command (and a JSON config for other clients) with your URL filled in. For other clients see [Connecting clients](connecting-clients.md).

## 6. Try it

Start a session and ask, for example:

- "List my Forge servers."
- "Which sites are on server `<name>`, and what is their deployment status?"
- "Show the last deploy log for site `<name>`."
- "Read the last 100 lines of the application log of `<site>`."
- "Diagnose the last failed deploy of `<site>`." (uses the `diagnose_failed_deploy` prompt if your client supports prompts)

Then open **Audit log** in the dashboard: every call the agent made is there, with its outcome.

## Next steps

- Grant more capabilities, per organization, server or site: [Permissions](permissions.md).
- Understand what is protected and what is not: [Security](security.md).
- See every tool: [Tool reference](tools.md).
