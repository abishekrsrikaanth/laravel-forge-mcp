<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use App\Mcp\Support\Defaults;
use App\Mcp\Support\ToolError;
use App\Models\User;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Laravel\Sanctum\PersonalAccessToken;

#[Description('Guided, read-only diagnosis of the most recent deployment of a Forge site.')]
class DiagnoseFailedDeploy extends Prompt
{
    protected string $name = 'diagnose_failed_deploy';

    /** Only offered to tokens that can use every tool the instructions name. */
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        $engine = app(PermissionEngine::class);

        return $engine->allowsAnywhere($token, Capability::View) && $engine->allowsAnywhere($token, Capability::ViewLogs);
    }

    public function arguments(): array
    {
        return [
            new Argument('organization', 'Organization slug. May be omitted when only one is available.', false),
            new Argument('server_id', 'Forge server id the site lives on. Defaults to the configured server if omitted.', false),
            new Argument('site_id', 'Forge site id (from list_sites). Defaults to the configured site if omitted.', false),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'organization' => ['nullable', 'string'],
            'server_id' => ['nullable', 'integer', 'min:1'],
            'site_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        try {
            $defaults = $token instanceof PersonalAccessToken ? Defaults::resolve($request, $token) : null;
        } catch (ToolError $e) {
            return Response::error($e->getMessage());
        }

        $requested = $data['organization'] ?? null;
        $explicitOrg = is_string($requested) && $requested !== '';
        $org = $explicitOrg ? $requested : $defaults?->organization;
        $organization = '';
        $exposed = $user instanceof User ? ($user->forgeConnection?->exposedOrganizations() ?? []) : [];

        if ($org === null && count($exposed) > 1) {
            return Response::error('Several organizations are available ('.implode(', ', $exposed).'). Pass the `organization` argument.');
        }

        if ($org !== null) {

            if (! in_array($org, $exposed, true)) {
                // Never interpolate unvetted text: it would land in the agent's instructions.
                $shown = preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $org) === 1 ? "Organization '{$org}'" : 'That organization';

                return Response::error("{$shown} is not available.");
            }

            $organization = " in organization {$org}";
        }

        // Same rules as ForgeTool: a default applies unless the default organization differs from the one in use.
        $defaultsApply = $defaults !== null && ($defaults->organization === null || $defaults->organization === $org);

        $serverId = isset($data['server_id']) ? (int) $data['server_id'] : ($defaultsApply ? $defaults->serverId : null);
        $siteId = isset($data['site_id'])
            ? (int) $data['site_id']
            : ($defaultsApply && $defaults->serverId === $serverId ? $defaults->siteId : null);

        if ($serverId === null || $siteId === null) {
            return Response::error('server_id and site_id are required (no default site is configured).');
        }

        return Response::text(
            "Diagnose the most recent deployment of site {$siteId} on server {$serverId}{$organization}. "
            .'Call get_deployment_status, then list_deployments (limit 3), then get_deployment_log for the latest failed deployment, then get_site_log with type application (lines 100). '
            .'Summarise the most likely cause in 3–5 bullets, quote the decisive log lines, and propose a fix. Do not deploy or change anything.'
        );
    }
}
