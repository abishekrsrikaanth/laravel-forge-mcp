# Contributing

Thanks for helping improve Forge MCP.

## Local setup

Requirements: PHP 8.4+, Composer, Node.js 22.

```bash
git clone https://github.com/abishekrsrikaanth/laravel-forge-mcp.git
cd laravel-forge-mcp
composer install
npm ci && npm run build
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
composer run dev
```

Then visit `/setup` to create the owner account.

## Checks

All of these must pass before you open a pull request (CI runs the same):

```bash
php artisan test            # tests
vendor/bin/pint --test      # code style (run vendor/bin/pint to fix)
vendor/bin/phpstan analyse  # static analysis
```

New behaviour needs tests. New MCP tools must be registered in `app/Mcp/Servers/ForgeServer.php`, declare a capability, go through the permission checks and output scrubbing used by the existing tools, and have tests under `tests/Feature/Mcp/`.

## Commits and pull requests

- Use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`).
- Keep pull requests focused, describe what and why, and link the related issue.
- Update the README when you change tools, capabilities or install steps.
- Security issues go through [SECURITY.md](SECURITY.md), not public issues.
