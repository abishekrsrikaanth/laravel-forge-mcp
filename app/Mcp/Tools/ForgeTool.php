<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Audit\AuditLogger;
use App\Forge\ForgeClientFactory;
use App\Forge\ForgeException;
use App\Forge\ForgeNotConnectedException;
use App\Forge\WorkerSiteResolver;
use App\Mcp\Support\Defaults;
use App\Mcp\Support\ResolvedDefaults;
use App\Mcp\Support\ToolContext;
use App\Mcp\Support\ToolDenied;
use App\Mcp\Support\ToolError;
use App\Models\AuditEntry;
use App\Models\ForgeConnection;
use App\Models\User;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\Target;
use App\Security\SecretScrubber;
use App\Security\SecretSet;
use App\Security\SiteSecrets;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Base class for every Forge MCP tool. Its guard order is the product's safety spine:
 *
 *   ToolContext (token + Forge connection) → target → permission → Forge call → scrub → audit
 *
 * A tool implements capability() and execute(); execute() resolves the organization with
 * resolveOrganization() and wraps all Forge work in guarded(). A ToolError thrown anywhere in
 * execute() becomes a tool error response carrying its message.
 */
abstract class ForgeTool extends Tool
{
    public const string NOT_CONNECTED = 'Forge is not connected. Connect it in the Forge MCP dashboard.';

    public const string NO_TOKEN = 'This tool requires a Forge MCP access token.';

    /** Defaults for the current call (resolved once, lazily); reset by handle(). */
    private ?ResolvedDefaults $defaults = null;

    /** The source ('header'|'token') of any default that filled a value in the current call; audited as `_defaults`. */
    private ?string $defaultsUsed = null;

    abstract protected function capability(): Capability;

    abstract protected function execute(Request $request): Response;

    /**
     * Runs execute() and contains everything that escapes it: ToolDenied is audited as `denied`,
     * ToolError becomes its message, and any other exception becomes a generic error with a
     * scrubbed log line (the package fallback would return the raw message in debug mode).
     */
    public function handle(Request $request): Response
    {
        $started = hrtime(true);
        $this->defaults = null;
        $this->defaultsUsed = null;

        try {
            return $this->execute($request);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ToolDenied $e) {
            $ctx = $this->context($request);
            if ($ctx instanceof ToolContext) {
                $this->audit($ctx, $request, $e->target, AuditEntry::DENIED, $e->getMessage(), $started, $this->auditSecrets($ctx, $e->target));

                return Response::error($this->scrubToken($ctx, $e->getMessage()));
            }

            return Response::error($e->getMessage());
        } catch (ToolError $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            $ctx = $this->context($request);
            $token = $this->connection($request)?->token;
            $message = $this->unexpected($e, $token);

            if ($ctx instanceof ToolContext) {
                $this->audit($ctx, $request, null, AuditEntry::ERROR, $message, $started);
            }

            return Response::error($message);
        }
    }

    /** Hidden from tools/list (and therefore uncallable) unless the token holds the capability somewhere. */
    public function shouldRegister(Request $request): bool
    {
        $token = $this->accessToken($request);

        return $token !== null && app(PermissionEngine::class)->allowsAnywhere($token, $this->capability());
    }

    /**
     * Arguments as they should be stored in the audit log. Override to drop or mask secret values
     * (e.g. .env contents). String values are additionally scrubbed by the AuditLogger.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function redactArguments(array $args): array
    {
        return $args;
    }

    /**
     * Run $fn under the permission check, then scrub and audit its output.
     *
     * $target may be a Target or a closure that resolves one from Forge (see siteTarget()); it runs
     * after the Forge connection check and before the permission check.
     *
     * @param  Target|Closure(ToolContext): Target  $target
     * @param  Closure(ToolContext): string  $fn
     * @param  bool  $scrub  false only for tools whose purpose is to show secrets (e.g. view .env); the
     *                       Forge API token is removed regardless
     */
    protected function guarded(Request $request, Target|Closure $target, Closure $fn, bool $scrub = true): Response
    {
        $started = hrtime(true);

        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $resolved = $target instanceof Target ? $target : null;

        try {
            $resolved ??= $target($ctx);

            if (! $this->permits($ctx, $resolved)) {
                $message = "{$this->capability()->label()} is not permitted on {$resolved->describe()} for this access token. The user can enable it in the Forge MCP dashboard.";
                $this->audit($ctx, $request, $resolved, AuditEntry::DENIED, $message, $started, $this->auditSecrets($ctx, $resolved));

                return Response::error($message);
            }

            $output = $fn($ctx);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ForgeException $e) {
            if ($e->status === 401) {
                $ctx->connection->forceFill(['last_error' => $e->agentMessage()])->save();
            }

            return $this->failed($ctx, $request, $resolved, $e->agentMessage(), $started);
        } catch (ToolDenied $e) {
            $message = $this->scrubToken($ctx, $e->getMessage());
            $deniedOn = $e->target ?? $resolved;
            $this->audit($ctx, $request, $deniedOn, AuditEntry::DENIED, $message, $started, $this->auditSecrets($ctx, $deniedOn));

            return Response::error($message);
        } catch (ToolError|InvalidArgumentException $e) {
            return $this->failed($ctx, $request, $resolved, $e->getMessage(), $started);
        } catch (Throwable $e) {
            return $this->failed($ctx, $request, $resolved, $this->unexpected($e, $ctx->connection->token), $started);
        }

        $secrets = AuditLogger::forgeTokenSecret($ctx);
        if ($scrub) {
            $secrets = $secrets->merge($this->secretsFor($ctx, $resolved));
            $output = app(SecretScrubber::class)->scrub($output, $secrets);
        } else {
            // Shown as-is on purpose; only the Forge API token itself is removed.
            $output = str_replace($ctx->connection->token, '[REDACTED:FORGE_API_TOKEN]', $output);
        }

        if ($secrets->warnings !== []) {
            $output = rtrim($output)."\n\n".implode("\n", array_map(fn (string $w) => "Warning: {$w}", $secrets->warnings));
        }

        if ($ctx->connection->last_error !== null) {
            $ctx->connection->forceFill(['last_error' => null])->save();
        }

        $summary = $ctx->summary ?? "{$this->capability()->label()} on {$resolved->describe()}.";
        $this->audit($ctx, $request, $resolved, AuditEntry::SUCCESS, app(SecretScrubber::class)->scrub($summary, $secrets), $started, $scrub ? $secrets : null);

        return Response::text($output);
    }

    /** Whether the token may use this tool's capability on the target. Override only to widen the check (e.g. list tools that filter per item). */
    protected function permits(ToolContext $ctx, Target $target): bool
    {
        return app(PermissionEngine::class)->allows($ctx->token, $this->capability(), $target);
    }

    /**
     * The organization slug to act on: the `organization` argument, else the default organization (see
     * Defaults), else the only exposed organization. An explicit or default organization must be exposed.
     * Never calls Forge. Invalid X-Forge-* headers are rejected here even when every argument is explicit.
     *
     * @throws ToolError|ToolDenied ToolDenied (audited as `denied`) when the organization is not exposed
     */
    protected function resolveOrganization(Request $request): string
    {
        $exposed = $this->connection($request)?->exposedOrganizations();
        if ($exposed === null) {
            throw new ToolError(self::NOT_CONNECTED);
        }

        $defaults = $this->defaults($request);
        $org = $request->get('organization');

        if (($org === null || $org === '') && $defaults->organization !== null) {
            $org = $defaults->organization;
            $this->defaultsUsed = $defaults->source;
        }

        if ($org === null || $org === '') {
            return match (count($exposed)) {
                0 => throw new ToolError('No organizations are exposed to this MCP server. The user can expose one in the Forge MCP dashboard.'),
                1 => $exposed[0],
                default => throw new ToolError('Several organizations are available ('.implode(', ', $exposed).'). Pass the `organization` argument.'),
            };
        }

        if (! is_string($org) || ! in_array($org, $exposed, true)) {
            $shown = is_scalar($org) ? (string) $org : get_debug_type($org);

            throw new ToolDenied("Organization '{$shown}' is not available to this MCP server.", is_string($org) ? new Target($org) : null);
        }

        return $org;
    }

    /**
     * The server id to act on: the `server_id` argument, else the default server when the default
     * organization is unset or equals $org. The id is unverified; build the target with serverTarget(),
     * siteTarget(), workerTarget() or serverWideTarget() as before.
     *
     * @throws ToolError when there is neither
     */
    protected function resolveServerId(Request $request, string $org): int
    {
        $explicit = $request->get('server_id');
        if ($explicit !== null && $explicit !== '') {
            return (int) $explicit;
        }

        $defaults = $this->defaults($request);
        if ($defaults->serverId !== null && $this->defaultsApplyTo($defaults, $org)) {
            $this->defaultsUsed = $defaults->source;

            return $defaults->serverId;
        }

        throw new ToolError('server_id is required (no default server is configured for this project or token).');
    }

    /**
     * The site id to act on: the `site_id` argument, else the default site when the default server equals
     * $serverId (and the default organization is unset or equals $org). Verify it with siteTarget().
     *
     * @throws ToolError when there is neither
     */
    protected function resolveSiteId(Request $request, string $org, int $serverId): int
    {
        $explicit = $request->get('site_id');
        if ($explicit !== null && $explicit !== '') {
            return (int) $explicit;
        }

        $defaults = $this->defaults($request);
        if ($defaults->siteId !== null && $defaults->serverId === $serverId && $this->defaultsApplyTo($defaults, $org)) {
            $this->defaultsUsed = $defaults->source;

            return $defaults->siteId;
        }

        throw new ToolError("site_id is required (no default site is configured for server {$serverId}).");
    }

    /**
     * A site target whose server id comes from Forge: the site must be in the (cached) site list of
     * the server. Use this for every site-level tool; never build site targets from agent input alone.
     *
     * @throws ToolError|ForgeException
     */
    protected function siteTarget(ToolContext $ctx, string $org, int $serverId, int $siteId): Target
    {
        if (! $ctx->connection->exposes($org)) {
            throw new ToolDenied("Organization '{$org}' is not available to this MCP server.", new Target($org, $serverId, $siteId));
        }

        foreach ($ctx->forge->sites($org, $serverId) as $site) {
            if ((int) ($site['id'] ?? 0) === $siteId) {
                return new Target($org, $serverId, $siteId);
            }
        }

        throw new ToolError("Site {$siteId} is not on server {$serverId}.");
    }

    /**
     * A server target whose id is verified against the (cached) server list of the organization.
     * Use this for every server-level tool that takes a server id from the agent.
     *
     * @throws ToolError|ForgeException
     */
    protected function serverTarget(ToolContext $ctx, string $org, int $serverId): Target
    {
        if (! $ctx->connection->exposes($org)) {
            throw new ToolDenied("Organization '{$org}' is not available to this MCP server.", new Target($org, $serverId));
        }

        foreach ($ctx->forge->servers($org) as $server) {
            if ((int) ($server['id'] ?? 0) === $serverId) {
                return new Target($org, $serverId);
            }
        }

        throw new ToolError("Server {$serverId} is not in organization {$org}.");
    }

    /**
     * The target for a server-wide read whose data may include any site on the server (server-level cron
     * jobs, events and their output carry no site id). The capability is required on the server AND on every
     * site: the first site that denies it is returned so guarded() denies there; otherwise the server target.
     *
     * @throws ToolError|ForgeException
     */
    protected function serverWideTarget(ToolContext $ctx, string $org, int $serverId, Capability $capability): Target
    {
        $server = $this->serverTarget($ctx, $org, $serverId);
        $engine = app(PermissionEngine::class);

        foreach ($ctx->forge->sites($org, $serverId) as $site) {
            $siteId = (int) ($site['id'] ?? 0);

            if ($siteId > 0 && ! $engine->allows($ctx->token, $capability, new Target($org, $serverId, $siteId))) {
                return new Target($org, $serverId, $siteId);
            }
        }

        return $server;
    }

    /**
     * Additionally require this tool's capability on the target's whole server, for operations whose
     * blast radius is the server even when they name a site (e.g. a root worker or raw supervisor
     * config: the `forge` user and supervisor are shared by every site on the server).
     *
     * Returns $target unchanged when it is already a server target or is denied itself (guarded()
     * then denies on it); throws ToolDenied with the server target when only the server denies.
     *
     * @param  string  $why  what needs the server-level grant, e.g. "Running a worker as root"
     *
     * @throws ToolDenied
     */
    protected function requireServerPermission(ToolContext $ctx, Target $target, string $why): Target
    {
        if ($target->siteId === null || $target->organization === null || $target->serverId === null || ! $this->permits($ctx, $target)) {
            return $target;
        }

        $server = new Target($target->organization, $target->serverId);

        if (! app(PermissionEngine::class)->allows($ctx->token, $this->capability(), $server)) {
            throw new ToolDenied(
                "{$this->capability()->label()} is not permitted on {$server->describe()} for this access token. {$why} affects every site on the server, so it needs the permission on the server, not just on a site. The user can enable it in the Forge MCP dashboard.",
                $server,
            );
        }

        return $target;
    }

    /** The cached record name of a server, falling back to "server {id}". */
    protected function serverName(ToolContext $ctx, string $org, int $serverId): string
    {
        foreach ($ctx->forge->servers($org) as $server) {
            if ((int) ($server['id'] ?? 0) === $serverId && is_string($server['name'] ?? null) && $server['name'] !== '') {
                return $server['name'];
            }
        }

        return "server {$serverId}";
    }

    /**
     * The target governing a background process. Its site is inferred (see WorkerSiteResolver), so every
     * candidate site must permit $capability: the first candidate that does not is returned so guarded()
     * denies on it. With no candidates the server-level target is returned; otherwise the first candidate,
     * and every candidate is recorded in $ctx->scrubSiteIds so output is scrubbed with all their secrets.
     *
     * @param  array<string, mixed>  $worker  flattened background-process record
     */
    protected function workerTarget(ToolContext $ctx, string $org, int $serverId, array $worker, Capability $capability): Target
    {
        $candidates = WorkerSiteResolver::candidateSiteIds($ctx->forge, $org, $serverId, $worker);

        if ($candidates === []) {
            return new Target($org, $serverId);
        }

        $engine = app(PermissionEngine::class);

        foreach ($candidates as $siteId) {
            if (! $engine->allows($ctx->token, $capability, new Target($org, $serverId, $siteId))) {
                return new Target($org, $serverId, $siteId);
            }
        }

        // The output may come from any candidate site, so every candidate's secrets are scrubbed.
        $ctx->scrubSiteIds = $candidates;

        return new Target($org, $serverId, $candidates[0]);
    }

    protected function accessToken(Request $request): ?PersonalAccessToken
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    protected function connection(Request $request): ?ForgeConnection
    {
        $user = $request->user();

        return $user instanceof User ? $user->forgeConnection : null;
    }

    /** The defaults for this call; none without an access token. */
    private function defaults(Request $request): ResolvedDefaults
    {
        if ($this->defaults === null) {
            $token = $this->accessToken($request);
            $this->defaults = $token !== null ? Defaults::resolve($request, $token) : ResolvedDefaults::none();
        }

        return $this->defaults;
    }

    private function defaultsApplyTo(ResolvedDefaults $defaults, string $org): bool
    {
        return $defaults->organization === null || $defaults->organization === $org;
    }

    private function context(Request $request): ToolContext|Response
    {
        $user = $request->user();
        $token = $this->accessToken($request);

        if (! $user instanceof User || $token === null) {
            return Response::error(self::NO_TOKEN);
        }

        try {
            $forge = app(ForgeClientFactory::class)->forUser($user);
        } catch (ForgeNotConnectedException) {
            return Response::error(self::NOT_CONNECTED);
        }

        $connection = $user->forgeConnection;
        if ($connection === null) {
            return Response::error(self::NOT_CONNECTED);
        }

        return new ToolContext($user, $token, $forge, $connection);
    }

    private function secretsFor(ToolContext $ctx, Target $target): SecretSet
    {
        if ($target->organization === null || $target->serverId === null) {
            return SecretSet::empty();
        }

        if ($target->siteId === null) {
            return SiteSecrets::forServer($ctx->forge, $target->organization, $target->serverId);
        }

        $set = SecretSet::empty();
        foreach ($this->scrubSites($ctx, $target) as $siteId) {
            $set = $set->merge(SiteSecrets::forSite($ctx->forge, $target->organization, $target->serverId, $siteId));
        }

        return $set;
    }

    /**
     * The site target's id plus any further sites the target closure recorded in $ctx->scrubSiteIds.
     *
     * @return list<int>
     */
    private function scrubSites(ToolContext $ctx, Target $target): array
    {
        return array_values(array_unique([...($target->siteId !== null ? [$target->siteId] : []), ...$ctx->scrubSiteIds]));
    }

    private function failed(ToolContext $ctx, Request $request, ?Target $target, string $message, int $started): Response
    {
        $message = $this->scrubToken($ctx, $message);
        $this->audit($ctx, $request, $target, AuditEntry::ERROR, $message, $started, $this->auditSecrets($ctx, $target));

        return Response::error($message);
    }

    private function audit(ToolContext $ctx, Request $request, ?Target $target, string $outcome, string $summary, int $started, ?SecretSet $secrets = null): void
    {
        app(AuditLogger::class)->record(
            $ctx,
            $this->name(),
            $target,
            $outcome,
            $summary,
            $this->auditArguments($request),
            (int) ((hrtime(true) - $started) / 1_000_000),
            $secrets,
        );
    }

    /**
     * The audited arguments, marked with `_defaults` when a default filled in a value.
     *
     * @return array<string, mixed>
     */
    private function auditArguments(Request $request): array
    {
        $arguments = $this->redactArguments($request->all());

        // The marker is ours alone: drop anything an agent sent under that name.
        unset($arguments['_defaults']);

        if ($this->defaultsUsed !== null) {
            $arguments['_defaults'] = $this->defaultsUsed;
        }

        return $arguments;
    }

    /**
     * Secrets already cached for a site target, for scrubbing audit arguments after a failure or denial. Never
     * calls Forge and never refills the cache (a failed .env write forgets it on purpose).
     */
    private function auditSecrets(ToolContext $ctx, ?Target $target): ?SecretSet
    {
        if ($target?->organization === null || $target->siteId === null) {
            return null;
        }

        $set = null;
        foreach ($this->scrubSites($ctx, $target) as $siteId) {
            $peeked = SiteSecrets::peek($target->organization, $siteId);
            if ($peeked !== null) {
                $set = ($set ?? SecretSet::empty())->merge($peeked);
            }
        }

        return $set;
    }

    /** Log a scrubbed line (never the raw exception, which may hold the Forge token) and return the agent-facing message. */
    private function unexpected(Throwable $e, ?string $forgeToken): string
    {
        $secrets = $forgeToken !== null ? SecretSet::of(['FORGE_API_TOKEN' => $forgeToken]) : SecretSet::empty();

        Log::error('Forge MCP tool failed', [
            'tool' => $this->name(),
            'exception' => $e::class,
            'message' => app(SecretScrubber::class)->scrub($e->getMessage(), $secrets),
        ]);

        return "Unexpected error while running {$this->name()}. Check the Forge MCP server logs.";
    }

    /** Remove the Forge API token (and well-known token formats) from text. */
    private function scrubToken(ToolContext $ctx, string $text): string
    {
        return app(SecretScrubber::class)->scrub($text, AuditLogger::forgeTokenSecret($ctx));
    }
}
