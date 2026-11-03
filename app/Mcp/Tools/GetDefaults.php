<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\Defaults;
use App\Mcp\Support\ToolContext;
use App\Mcp\Tools\Concerns\DescribesDefaults;
use App\Models\TokenDefault;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\Target;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Show the default organization, server and site in effect for this call and where they come from (project headers or this access token).')]
#[IsReadOnly]
class GetDefaults extends ForgeTool
{
    use DescribesDefaults;

    protected string $name = 'get_defaults';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request): Response
    {

        return $this->guarded($request, new Target, function (ToolContext $ctx) use ($request) {
            $effective = Defaults::resolve($request, $ctx->token);
            $ctx->summary = 'Read token defaults.';

            if ($effective->source === null) {
                return 'No defaults are active. Use set_defaults to store some for this access token.';
            }

            $lines = ["Effective defaults ({$this->sourceLabel($effective->source)}): ".$this->describeDefaults($ctx, $effective->organization, $effective->serverId, $effective->siteId).'.'];

            if ($effective->source === 'header') {
                $row = TokenDefault::query()->where('personal_access_token_id', $ctx->token->getKey())->first();
                if ($row !== null) {
                    $lines[] = 'Stored on this token (overridden by project headers): '.$this->describeDefaults($ctx, $row->organization, $row->server_id, $row->site_id).'.';
                }
            }

            return implode("\n", $lines);
        });
    }

    /** Needs View somewhere (shouldRegister already checks); the output names only the token's own defaults. */
    protected function permits(ToolContext $ctx, Target $target): bool
    {
        return app(PermissionEngine::class)->allowsAnywhere($ctx->token, $this->capability());
    }
}
