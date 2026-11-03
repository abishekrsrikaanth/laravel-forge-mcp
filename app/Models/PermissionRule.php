<?php

namespace App\Models;

use App\Permissions\Capability;
use App\Permissions\ScopeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property int $personal_access_token_id
 * @property Capability $capability
 * @property ScopeType $scope_type
 * @property string|null $organization
 * @property int|null $server_id
 * @property int|null $site_id
 * @property bool $allowed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PermissionRule extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capability' => Capability::class,
            'scope_type' => ScopeType::class,
            'server_id' => 'integer',
            'site_id' => 'integer',
            'allowed' => 'boolean',
        ];
    }

    /** @return BelongsTo<PersonalAccessToken, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
