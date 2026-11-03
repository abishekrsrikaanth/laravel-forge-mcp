# Installation

Forge MCP is a standard Laravel application. You can host it on a Forge server (recommended, since you already have one) or on any host that runs PHP 8.4.

## Requirements

- PHP 8.4 or newer, with Composer.
- Node.js 22 and npm, to build the front-end assets.
- A database: SQLite, MySQL or Postgres. SQLite is enough for a single-owner install.
- HTTPS. Access tokens travel in an `Authorization` header, so never serve Forge MCP over plain HTTP.
- A scheduler that runs `php artisan schedule:run` every minute, so old audit log entries are pruned.
- A Laravel Forge account and a Forge API token (see [Forge API token](forge-api-token.md)).

No queue worker is needed.

## Install on Laravel Forge

### 1. Create the site

In Forge, create a new site on a server (for example `mcp.example.com`) and install it from the Git repository `abishekrsrikaanth/laravel-forge-mcp` (or your fork).

### 2. Environment variables

Edit the site's environment (`.env`) in Forge. Start from `.env.example` and set at least:

| Variable | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Generate one with `php artisan key:generate --show` and paste it. Keep it stable: it encrypts the stored Forge token. |
| `APP_URL` | The public `https://` URL of the site. The client snippets in the dashboard use it. |
| `DB_CONNECTION` and `DB_*` | Your database. With SQLite, `DB_CONNECTION=sqlite` uses `database/database.sqlite`. |

The Forge MCP tunables (`FORGE_MCP_*`) are optional. See [Configuration](configuration.md) for every variable.

### 3. Database

For MySQL or Postgres, create a database and user in Forge and fill in `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. For SQLite, make sure `database/database.sqlite` exists on the server before the first migration (for example by running `touch database/database.sqlite` in the site directory once).

### 4. Deployment script

Replace the site's deployment script with:

```bash
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci && npm run build

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan optimize
```

`artisan optimize` caches the configuration, so changes to `.env` take effect on the next deploy (or after `php artisan config:cache`).

### 5. Scheduler

Enable the scheduler for the site (in Forge, add a scheduled job that runs `php artisan schedule:run` every minute as the site user, with the site path as working directory). The only scheduled task prunes audit log entries older than the retention period, once a day.

### 6. SSL

Enable SSL for the site (Let's Encrypt is fine). Always use the `https://` URL for MCP clients.

### 7. Deploy and claim /setup immediately

Deploy, then **immediately** open `https://mcp.example.com/setup` and create the owner account. Setup can be completed once; afterwards the page returns 404. Until the owner exists, every dashboard page redirects to `/setup` and anyone who can reach the app can claim it. If you cannot complete setup right away, restrict access to the site (for example by IP) until you have.

### 8. Connect Forge

Open **Forge connection** in the dashboard and paste your Forge API token. Then continue with [Connecting clients](connecting-clients.md).

## Install on any PHP 8.4 host

```bash
git clone https://github.com/abishekrsrikaanth/laravel-forge-mcp.git
cd laravel-forge-mcp
composer install --no-dev
npm ci && npm run build
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # when using SQLite
php artisan migrate --force
```

Then:

1. Edit `.env` as described in [Environment variables](#2-environment-variables) (at least `APP_ENV=production`, `APP_DEBUG=false` and `APP_URL`).
2. Serve the `public/` directory with PHP-FPM behind Nginx or Caddy, over HTTPS. Make `storage/` and `bootstrap/cache/` (and the SQLite file, if used) writable by the PHP user.
3. Run `php artisan schedule:run` every minute (cron or a systemd timer).
4. Optionally run `php artisan optimize` after each deploy.
5. Visit `/setup` straight away and create the owner account.

`GET /up` is a health check endpoint that returns 200 when the app boots.

## Upgrading

1. On Forge, deploy the site (the deployment script pulls, installs dependencies, builds assets, runs migrations and re-caches the configuration). Elsewhere, run the equivalent:

   ```bash
   git pull
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   php artisan migrate --force
   php artisan optimize
   ```

2. Reload PHP-FPM so the new code is picked up.

Access tokens, permissions, the Forge connection and the audit log live in the database and survive upgrades. Do not change `APP_KEY` during an upgrade: the stored Forge token is encrypted with it.

## Uninstalling

1. In Forge, delete (revoke) the Forge API token you gave Forge MCP. This cuts off access even if the app or its database still exists somewhere.
2. Remove the MCP server from each client (for Claude Code: `claude mcp remove forge-mcp`).
3. Optionally, in the dashboard, use **Disconnect** on the **Forge connection** page, which deletes the stored token.
4. Delete the site in Forge (or remove the app from your host), and drop its database if it used MySQL or Postgres.
