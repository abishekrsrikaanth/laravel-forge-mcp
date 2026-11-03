<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Forge\ForgeException;
use App\Mcp\Support\ToolContext;
use App\Mcp\Tools\Concerns\NamesSites;
use App\Permissions\Capability;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;
use Throwable;

/**
 * Puts a site into Laravel maintenance mode. The bypass secret is never echoed: it is masked in the
 * audit arguments and a Forge validation failure is reported without Forge's (possibly echoing) detail.
 */
#[Description('Put a Forge site into Laravel maintenance mode, so visitors get the maintenance response instead of the app. Optionally set a bypass secret, an HTTP status (304, 307, 410 or 503; default 503) and a redirect (relative path or http(s) URL). The change is applied asynchronously; check get_maintenance_mode.')]
#[IsDestructive]
class EnableMaintenanceMode extends ForgeTool
{
    use NamesSites;

    private const array AUDITED_ARGUMENTS = ['organization', 'server_id', 'site_id', 'status', 'redirect'];

    private const array STATUSES = [304, 307, 410, 503];

    protected string $name = 'enable_maintenance_mode';

    protected function capability(): Capability
    {
        return Capability::MaintenanceMode;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id the site lives on.'),
            'site_id' => $schema->integer()->required()->description('Forge site id (from list_sites).'),
            'secret' => $schema->string()->description('Optional bypass secret (max 128 characters, no whitespace).'),
            'status' => $schema->integer()->enum(self::STATUSES)->default(503)->description('HTTP status returned to visitors: 304, 307, 410 or 503.'),
            'redirect' => $schema->string()->description('Optional redirect target: a relative path starting with "/" or an http(s) URL.'),
        ];
    }

    /** Only known arguments are stored, and the secret is masked. */
    protected function redactArguments(array $args): array
    {
        $kept = array_intersect_key($args, array_flip(self::AUDITED_ARGUMENTS));

        if (array_key_exists('secret', $args)) {
            $kept['secret'] = '[REDACTED]';
        }

        return $kept;
    }

    protected function execute(Request $request): Response
    {
        $request->validate([
            'secret' => ['nullable', 'string', 'max:128', 'regex:/^\S+$/'],
            'status' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_int($value) || ! in_array($value, self::STATUSES, true)) {
                    $fail('The status must be one of 304, 307, 410, 503.');
                }
            }],
            'redirect' => ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! self::validRedirect($value)) {
                    $fail('The redirect must be a relative path starting with "/" or an http(s) URL.');
                }
            }],
        ]);

        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $siteId = (int) $request->get('site_id');
        $secret = $request->get('secret') !== null ? (string) $request->get('secret') : null;
        $status = (int) ($request->get('status') ?? 503);
        $redirect = $request->get('redirect') !== null ? (string) $request->get('redirect') : null;

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $secret, $status, $redirect) {
                try {
                    $ctx->forge->enableMaintenance($org, $serverId, $siteId, $secret, $status, $redirect);
                } catch (Throwable $e) {
                    throw $this->withoutDetail($e);
                }
                $ctx->summary = "Enabled maintenance mode for site {$siteId} (HTTP {$status})";

                return "Maintenance mode is being enabled for {$this->siteLabel($ctx, $org, $serverId, $siteId)} (HTTP {$status}).";
            },
        );
    }

    private static function validRedirect(string $value): bool
    {
        if (preg_match('/[\s\x00-\x1f]/', $value) === 1) {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return ! str_starts_with($value, '//') && ! str_contains($value, '\\');
        }

        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }

    /** Forge statuses are kept (guarded() records a 401); a 422 and unknown errors lose their detail. */
    private function withoutDetail(Throwable $e): Throwable
    {
        return match (true) {
            $e instanceof ForgeException && $e->status === 422 => new ForgeException('Forge rejected the maintenance mode request (422).', 422),
            $e instanceof ForgeException => $e,
            default => new RuntimeException($e::class),
        };
    }
}
