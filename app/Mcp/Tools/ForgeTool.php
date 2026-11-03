<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Audit\AuditLogger;
use App\Forge\ForgeClientFactory;
use App\Forge\ForgeException;
use App\Forge\ForgeNotConnectedException;
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

        try {
            return $this->execute($request);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ToolDenied $e) {
            $ctx = $this->context($request);
            if ($ctx instanceof ToolContext) {
                $this->audit($ctx, $request, $e->target, AuditEntry::DENIED, $e->getMessage(), $started);

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
                $this->audit($ctx, $request, $resolved, AuditEntry::DENIED, $message, $started);

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
            $this->audit($ctx, $request, $e->target ?? $resolved, AuditEntry::DENIED, $message, $started);

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
        $this->audit($ctx, $request, $resolved, AuditEntry::SUCCESS, app(SecretScrubber::class)->scrub($summary, $secrets), $started);

        return Response::text($output);
    }

    /** Whether the token may use this tool's capability on the target. Override only to widen the check (e.g. list tools that filter per item). */
    protected function permits(ToolContext $ctx, Target $target): bool
    {
        return app(PermissionEngine::class)->allows($ctx->token, $this->capability(), $target);
    }

    /**
     * The organization slug to act on: the `organization` argument if it is exposed, or the only
     * exposed organization when the argument is omitted. Never calls Forge.
     *
     * @throws ToolError|ToolDenied ToolDenied (audited as `denied`) when the organization is not exposed
     */
    protected function resolveOrganization(Request $request): string
    {
        $exposed = $this->connection($request)?->exposedOrganizations();
        if ($exposed === null) {
            throw new ToolError(self::NOT_CONNECTED);
        }

        $org = $request->get('organization');

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

        return $target->siteId !== null
            ? SiteSecrets::forSite($ctx->forge, $target->organization, $target->serverId, $target->siteId)
            : SiteSecrets::forServer($ctx->forge, $target->organization, $target->serverId);
    }

    private function failed(ToolContext $ctx, Request $request, ?Target $target, string $message, int $started): Response
    {
        $message = $this->scrubToken($ctx, $message);
        $this->audit($ctx, $request, $target, AuditEntry::ERROR, $message, $started);

        return Response::error($message);
    }

    private function audit(ToolContext $ctx, Request $request, ?Target $target, string $outcome, string $summary, int $started): void
    {
        app(AuditLogger::class)->record(
            $ctx,
            $this->name(),
            $target,
            $outcome,
            $summary,
            $this->redactArguments($request->all()),
            (int) ((hrtime(true) - $started) / 1_000_000),
        );
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
