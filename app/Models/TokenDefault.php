<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The default organization/server/site of one MCP access token, used when a tool call omits them and
 * the request carries no X-Forge-* headers. A site default requires a server default.
 *
 * @property int $id
 * @property int $personal_access_token_id
 * @property string $organization
 * @property int|null $server_id
 * @property int|null $site_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TokenDefault extends Model
{
    protected $table = 'mcp_token_defaults';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'site_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PersonalAccessToken, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
