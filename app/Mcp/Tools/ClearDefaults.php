<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\Defaults;
use App\Mcp\Support\ToolContext;
use App\Models\TokenDefault;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\Target;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Remove the defaults stored for this access token. Project .mcp.json headers, if any, still apply.')]
class ClearDefaults extends ForgeTool
{
    protected string $name = 'clear_defaults';

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
            $headersActive = Defaults::resolve($request, $ctx->token)->source === 'header'; // may throw: resolve before deleting
            $deleted = TokenDefault::query()->where('personal_access_token_id', $ctx->token->getKey())->delete() > 0;
            $ctx->summary = $deleted ? 'Cleared token defaults.' : 'No token defaults to clear.';

            $message = $deleted ? 'Defaults cleared for this access token.' : 'This access token has no stored defaults.';

            if ($headersActive) {
                $message .= ' Project headers still apply and override any token defaults.';
            }

            return $message;
        });
    }

    protected function permits(ToolContext $ctx, Target $target): bool
    {
        return app(PermissionEngine::class)->allowsAnywhere($ctx->token, $this->capability());
    }
}
