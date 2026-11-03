<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Forge\ForgeClient;
use App\Forge\ForgeException;
use App\Mcp\Support\ToolContext;
use App\Mcp\Support\ToolError;
use App\Permissions\Capability;
use App\Security\SiteSecrets;
use App\Support\EnvFile;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;
use Throwable;

/**
 * Edits individual .env keys: fetch the file, merge with EnvFile::apply() (comments and order are
 * kept), write it back and re-seed the cached secrets for the site. The read-modify-write runs under
 * a per-site cache lock so two edits through this tool cannot lose each other's changes.
 *
 * Neither the file nor any value (old or new) may reach the agent, the audit log or the app log:
 * the response and summary list key names only, redactArguments() keeps only known arguments and
 * masks every `set` value, and a failed write is reported without Forge's (possibly echoing)
 * detail. edit_env must not imply view_env, so old values are protected as strictly as new ones.
 */
#[Description('Set or remove individual keys in the .env file of a Forge site. Comments and other keys are kept. Returns the names of the keys added, changed and removed (never values). Changes take effect on the next deployment or config reload. Edits made to the .env outside this tool at the same moment may be overwritten.')]
#[IsDestructive]
class SetSiteEnvValues extends ForgeTool
{
    private const AUDITED_ARGUMENTS = ['organization', 'server_id', 'site_id', 'set', 'remove'];

    /** Covers the read-modify-write's up to three Forge calls (each bounded by the client timeout) plus margin. */
    private const LOCK_SECONDS = 3 * ForgeClient::TIMEOUT_SECONDS + 15;

    private const LOCK_WAIT_SECONDS = 10;

    protected string $name = 'set_site_env_values';

    protected function capability(): Capability
    {
        return Capability::EditEnv;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id the site lives on. Defaults to the configured server if omitted.'),
            'site_id' => $schema->integer()->description('Forge site id (from list_sites). Defaults to the configured site if omitted.'),
            'set' => $schema->object()->description('Keys to add or change, as an object of KEY => string value.'),
            'remove' => $schema->array()->items($schema->string())->description('Keys to remove.'),
        ];
    }

    /** Only known arguments are stored; every `set` value is masked. Anything else could hold a secret. */
    protected function redactArguments(array $args): array
    {
        $args = array_intersect_key($args, array_flip(self::AUDITED_ARGUMENTS));

        if (array_key_exists('set', $args)) {
            $args['set'] = is_array($args['set'])
                ? array_map(static fn (): string => '[REDACTED]', $args['set'])
                : '[REDACTED]';
        }

        return $args;
    }

    protected function execute(Request $request): Response
    {
        [$set, $remove] = $this->changes($request);

        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $siteId = $this->resolveSiteId($request, $org, $serverId);

        return $this->guarded(
            $request,
            function (ToolContext $ctx) use ($org, $serverId, $siteId) {
                $this->serverTarget($ctx, $org, $serverId);

                return $this->siteTarget($ctx, $org, $serverId, $siteId);
            },
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $set, $remove) {
                try {
                    return Cache::lock("forge-mcp:env-edit:{$org}:{$siteId}", self::LOCK_SECONDS)->block(
                        self::LOCK_WAIT_SECONDS,
                        fn (): string => $this->edit($ctx, $org, $serverId, $siteId, $set, $remove),
                    );
                } catch (LockTimeoutException) {
                    throw new ToolError('Another .env edit for this site is in progress; try again.');
                }
            },
        );
    }

    /**
     * Fetch, merge and write the .env. Must run under the site's edit lock.
     *
     * @param  array<string, string>  $set
     * @param  list<string>  $remove
     */
    private function edit(ToolContext $ctx, string $org, int $serverId, int $siteId, array $set, array $remove): string
    {
        try {
            $current = $ctx->forge->environmentOrNull($org, $serverId, $siteId);
            if ($current === null) {
                throw new ToolError('Could not read the current .env; refusing to overwrite it.');
            }

            $change = EnvFile::apply($current, $set, $remove);
            $site = $this->siteName($ctx, $org, $serverId, $siteId);
        } catch (ToolError|InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->withoutDetail($e);
        }

        if ($change->added === [] && $change->changed === [] && $change->removed === []) {
            $ctx->summary = "No changes to .env for site {$siteId}.";

            return "No changes to .env for {$site}.";
        }

        try {
            // Forge's optional `cache`/`queues` flags are deliberately not sent (Forge defaults apply).
            $ctx->forge->updateEnvironment($org, $serverId, $siteId, $change->contents);
        } catch (Throwable $e) {
            // The write may or may not have landed: drop the cache so the next scrub refetches.
            SiteSecrets::forget($org, $siteId);

            throw $this->withoutDetail($e);
        }

        // Keep both the rotated-out and the new values masked by other tools.
        SiteSecrets::remember($org, $siteId, EnvFile::parse($change->contents), EnvFile::parse($current));

        $names = static fn (array $keys): string => $keys === [] ? 'none' : implode(', ', $keys);
        $details = "Added: {$names($change->added)}. Changed: {$names($change->changed)}. Removed: {$names($change->removed)}.";
        $ctx->summary = "Updated .env for site {$siteId}. {$details}";

        return "Updated .env for {$site}. {$details}";
    }

    /**
     * Validate the arguments before anything is sent to Forge.
     *
     * @return array{0: array<string, string>, 1: list<string>}
     *
     * @throws ToolError
     */
    private function changes(Request $request): array
    {
        $set = $request->get('set') ?? [];
        $remove = $request->get('remove') ?? [];

        if (! is_array($set) || ($set !== [] && array_is_list($set))) {
            throw new ToolError('`set` must be an object of KEY => string value.');
        }
        if (! is_array($remove) || ! array_is_list($remove)) {
            throw new ToolError('`remove` must be a list of key names.');
        }
        if ($set === [] && $remove === []) {
            throw new ToolError('Pass `set` and/or `remove` with at least one key.');
        }

        $values = [];
        foreach ($set as $key => $value) {
            $key = (string) $key;
            if (! is_string($value)) {
                throw new ToolError("The value for {$key} must be a string.");
            }
            if (str_contains($value, "\0")) {
                throw new ToolError("The value for {$key} must not contain NUL characters.");
            }
            $values[$key] = $value;
        }

        $keys = [];
        foreach ($remove as $key) {
            if (! is_string($key)) {
                throw new ToolError('`remove` must be a list of key names.');
            }
            $keys[] = $key;
        }

        try {
            // Dry run on an empty file: applies EnvFile's key rules and the set/remove overlap check.
            EnvFile::apply('', $values, $keys);
        } catch (InvalidArgumentException $e) {
            throw new ToolError($e->getMessage());
        }

        return [$values, $keys];
    }

    /**
     * The exception to let guarded() handle, without any detail that could echo .env values (which
     * the scrubber may not recognise: short, numeric or public-looking values). Forge statuses are
     * kept so guarded() still records a 401; every other Forge message is already fixed text.
     */
    private function withoutDetail(Throwable $e): Throwable
    {
        return match (true) {
            $e instanceof ForgeException && $e->status === 422 => new ForgeException('Forge rejected the .env update (422).', 422),
            $e instanceof ForgeException => $e,
            default => new RuntimeException($e::class),
        };
    }

    private function siteName(ToolContext $ctx, string $org, int $serverId, int $siteId): string
    {
        foreach ($ctx->forge->sites($org, $serverId) as $site) {
            if ((int) ($site['id'] ?? 0) === $siteId && is_string($site['name'] ?? null) && $site['name'] !== '') {
                return $site['name'];
            }
        }

        return "site {$siteId}";
    }
}
