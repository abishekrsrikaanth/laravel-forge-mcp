<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Forge\ForgeClient;
use App\Models\ForgeConnection;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * What a guarded tool body runs with: the owner, the MCP access token making the call and a
 * Forge client for the owner's connection.
 */
final class ToolContext
{
    /** One-line audit summary; the tool body may set it (it is scrubbed before it is stored). */
    public ?string $summary = null;

    /**
     * Further sites (on the target's server) whose secrets must also be scrubbed from the output and
     * audit row, e.g. every candidate site of a worker whose site is ambiguous. Set by the target closure.
     *
     * @var list<int>
     */
    public array $scrubSiteIds = [];

    public function __construct(
        public readonly User $user,
        public readonly PersonalAccessToken $token,
        public readonly ForgeClient $forge,
        public readonly ForgeConnection $connection,
    ) {}
}
