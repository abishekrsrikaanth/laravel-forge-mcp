<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Forge\ForgeException;
use App\Mcp\Support\ToolContext;
use App\Mcp\Support\ToolError;
use App\Permissions\Capability;
use App\Security\SecretScrubber;
use App\Security\SecretSet;
use App\Security\SiteSecrets;
use App\Support\EnvFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;
use Throwable;

/**
 * Edits individual .env keys: fetch the file, merge with EnvFile::apply() (comments and order are
 * kept), write it back and drop the cached secrets for the site.
 *
 * Neither the file nor any value (old or new) may reach the agent, the audit log or the app log:
 * the response and summary list key names only, redactArguments() masks every `set` value, and a
 * failed Forge write is re-thrown with its message scrubbed of the old and new values.
 */
#[Description('Set or remove individual keys in the .env file of a Forge site. Comments and other keys are kept. Returns the names of the keys added, changed and removed (never values). Changes take effect on the next deployment or config reload.')]
#[IsDestructive]
class SetSiteEnvValues extends ForgeTool
{
    protected string $name = 'set_site_env_values';

    protected function capability(): Capability
    {
        return Capability::EditEnv;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id the site lives on.'),
            'site_id' => $schema->integer()->required()->description('Forge site id (from list_sites).'),
            'set' => $schema->object()->description('Keys to add or change, as an object of KEY => string value.'),
            'remove' => $schema->array()->items($schema->string())->description('Keys to remove.'),
        ];
    }

    protected function redactArguments(array $args): array
    {
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
        $serverId = (int) $request->get('server_id');
        $siteId = (int) $request->get('site_id');

        return $this->guarded(
            $request,
            function (ToolContext $ctx) use ($org, $serverId, $siteId) {
                $this->serverTarget($ctx, $org, $serverId);

                return $this->siteTarget($ctx, $org, $serverId, $siteId);
            },
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $set, $remove) {
                $current = $ctx->forge->environment($org, $serverId, $siteId);
                $change = EnvFile::apply($current, $set, $remove);
                $site = $this->siteName($ctx, $org, $serverId, $siteId);

                if ($change->added === [] && $change->changed === [] && $change->removed === []) {
                    $ctx->summary = "No changes to .env for site {$siteId}.";

                    return "No changes to .env for {$site}.";
                }

                try {
                    // Forge's optional `cache`/`queues` flags are deliberately not sent (Forge defaults apply).
                    $ctx->forge->updateEnvironment($org, $serverId, $siteId, $change->contents);
                } catch (Throwable $e) {
                    throw $this->scrubbed($e, SecretSet::of($set)->merge(SecretSet::of(EnvFile::parse($current))));
                } finally {
                    SiteSecrets::forget($org, $siteId);
                }

                $names = static fn (array $keys): string => $keys === [] ? 'none' : implode(', ', $keys);
                $details = "Added: {$names($change->added)}. Changed: {$names($change->changed)}. Removed: {$names($change->removed)}.";
                $ctx->summary = "Updated .env for site {$siteId}. {$details}";

                return "Updated .env for {$site}. {$details}";
            },
        );
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

    /** The exception to let guarded() handle, with any old or new .env value scrubbed from its message. */
    private function scrubbed(Throwable $e, SecretSet $secrets): Throwable
    {
        $message = app(SecretScrubber::class)->scrub($e->getMessage(), $secrets);

        return $e instanceof ForgeException
            ? new ForgeException($message, $e->status)
            : new RuntimeException($e::class.': '.$message);
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
