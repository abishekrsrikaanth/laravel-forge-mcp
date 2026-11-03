<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $token_last_four
 * @property string $forge_user_name
 * @property string|null $forge_user_email
 * @property list<array{slug: string, name: string}> $organizations
 * @property list<string> $exposed_organizations
 * @property string|null $last_error
 * @property Carbon|null $validated_at
 */
#[Hidden(['token'])]
class ForgeConnection extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'organizations' => 'array',
            'exposed_organizations' => 'array',
            'validated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<string> */
    public function exposedOrganizations(): array
    {
        return $this->exposed_organizations;
    }

    public function exposes(?string $org): bool
    {
        return $org !== null && in_array($org, $this->exposedOrganizations(), true);
    }
}
