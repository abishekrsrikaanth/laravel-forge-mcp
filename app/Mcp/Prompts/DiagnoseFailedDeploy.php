<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

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
            new Argument('server_id', 'Forge server id the site lives on.', true),
            new Argument('site_id', 'Forge site id (from list_sites).', true),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'organization' => ['nullable', 'string'],
            'server_id' => ['required', 'integer', 'min:1'],
            'site_id' => ['required', 'integer', 'min:1'],
        ]);

        $organization = is_string($data['organization'] ?? null) && $data['organization'] !== '' ? " in organization {$data['organization']}" : '';

        return Response::text(
            "Diagnose the most recent deployment of site {$data['site_id']} on server {$data['server_id']}{$organization}. "
            .'Call get_deployment_status, then list_deployments (limit 3), then get_deployment_log for the latest failed deployment, then get_site_log with type application (lines 100). '
            .'Summarise the most likely cause in 3–5 bullets, quote the decisive log lines, and propose a fix. Do not deploy or change anything.'
        );
    }
}
