<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One MCP tool call. Rows are append-only.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $personal_access_token_id
 * @property string $token_name
 * @property string $tool
 * @property string|null $organization
 * @property int|null $server_id
 * @property int|null $site_id
 * @property string $outcome 'success'|'denied'|'error'
 * @property string $summary
 * @property array<string, mixed> $arguments
 * @property int $duration_ms
 * @property Carbon|null $created_at
 */
class AuditEntry extends Model
{
    use Prunable;

    public const string SUCCESS = 'success';

    public const string DENIED = 'denied';

    public const string ERROR = 'error';

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'server_id' => 'integer',
            'site_id' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('forge-mcp.audit_retention_days')));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
