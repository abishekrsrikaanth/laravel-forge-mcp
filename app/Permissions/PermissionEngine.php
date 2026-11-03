<?php

namespace App\Permissions;

use App\Models\ForgeConnection;
use App\Models\PermissionRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Decides whether an MCP access token may exercise a capability on a target.
 *
 * - Only tokens carrying the `mcp` ability (and not expired) are ever allowed anything.
 * - Organizations not exposed on the owner's Forge connection are denied for everything.
 * - The most specific matching rule wins: site > server > organization > global.
 *   A site rule matches on organization + site id; a server rule on organization + server id.
 *   If several rules match at the same level, any deny wins.
 * - Site rules are ignored for capabilities that do not allow site scope (restart services, reboot).
 * - No matching rule means denied.
 *
 * Bound as a scoped singleton: rules are memoized per token for the current request/job and the
 * memo is dropped for a token whenever set() changes its rules.
 */
final class PermissionEngine
{
    /** Resolution order, most specific first. */
    private const array SPECIFICITY = [ScopeType::Site, ScopeType::Server, ScopeType::Organization, ScopeType::Global];

    /** @var array<int, array<string, list<PermissionRule>>> token id => capability value => rules */
    private array $rules = [];

    public function allows(PersonalAccessToken $token, Capability $capability, Target $target): bool
    {
        if (! $this->isUsable($token)) {
            return false;
        }

        $exposed = $this->exposedOrganizations($token);

        if ($target->organization === null) {
            // Server and site ids are only meaningful inside an organization.
            if ($target->serverId !== null || $target->siteId !== null || $exposed === []) {
                return false;
            }
        } elseif (! in_array($target->organization, $exposed, true)) {
            return false;
        }

        $rules = $this->rulesFor($token, $capability);

        foreach (self::SPECIFICITY as $scope) {
            if ($scope === ScopeType::Site && ! $capability->allowsSiteScope()) {
                continue;
            }

            $matching = array_filter($rules, fn (PermissionRule $rule) => $rule->scope_type === $scope && $this->matches($rule, $target));

            if ($matching !== []) {
                foreach ($matching as $rule) {
                    if (! $rule->allowed) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Whether the capability is granted anywhere the token can reach: an allow rule exists (outside an
     * unexposed organization) and the owner has at least one exposed organization.
     */
    public function allowsAnywhere(PersonalAccessToken $token, Capability $capability): bool
    {
        if (! $this->isUsable($token)) {
            return false;
        }

        $exposed = $this->exposedOrganizations($token);

        if ($exposed === []) {
            return false;
        }

        foreach ($this->rulesFor($token, $capability) as $rule) {
            if (! $rule->allowed) {
                continue;
            }

            if ($rule->scope_type === ScopeType::Site && ! $capability->allowsSiteScope()) {
                continue;
            }

            if ($rule->organization === null || in_array($rule->organization, $exposed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create, replace or (with null) delete the rule for this token, capability and scope. Only the
     * target fields relevant to the scope are stored; site rules also keep the server id when given.
     */
    public function set(PersonalAccessToken $token, Capability $capability, ScopeType $scope, Target $target, ?bool $allowed): void
    {
        if ($scope === ScopeType::Site && ! $capability->allowsSiteScope()) {
            throw new InvalidArgumentException("{$capability->label()} cannot be granted per site; use server, organization or global scope.");
        }

        $key = match ($scope) {
            ScopeType::Global => ['organization' => null, 'server_id' => null, 'site_id' => null],
            ScopeType::Organization => ['organization' => $this->require($target->organization, $scope, 'an organization'), 'server_id' => null, 'site_id' => null],
            ScopeType::Server => [
                'organization' => $this->require($target->organization, $scope, 'an organization'),
                'server_id' => $this->require($target->serverId, $scope, 'a server id'),
                'site_id' => null,
            ],
            ScopeType::Site => [
                'organization' => $this->require($target->organization, $scope, 'an organization'),
                'site_id' => $this->require($target->siteId, $scope, 'a site id'),
            ],
        };

        $key = ['personal_access_token_id' => $token->getKey(), 'capability' => $capability->value, 'scope_type' => $scope->value] + $key;

        DB::transaction(function () use ($key, $scope, $target, $allowed) {
            PermissionRule::query()->where($key)->delete();

            if ($allowed !== null) {
                PermissionRule::query()->create($key + [
                    'server_id' => $scope === ScopeType::Site ? $target->serverId : $key['server_id'],
                    'allowed' => $allowed,
                ]);
            }
        });

        unset($this->rules[$token->getKey()]);
    }

    /**
     * "Read-only" when nothing beyond the read tier is granted; otherwise the granted capabilities, e.g.
     * "Deploy, Edit .env (2 scoped)" — a bare label means allowed globally, "+N scoped" adds narrower grants.
     */
    public function summary(PersonalAccessToken $token): string
    {
        $parts = [];

        foreach (Capability::cases() as $capability) {
            if ($capability->tier() === Tier::Read) {
                continue;
            }

            $global = false;
            $scoped = 0;

            foreach ($this->rulesFor($token, $capability) as $rule) {
                if (! $rule->allowed || ($rule->scope_type === ScopeType::Site && ! $capability->allowsSiteScope())) {
                    continue;
                }

                $rule->scope_type === ScopeType::Global ? $global = true : $scoped++;
            }

            if ($global) {
                $parts[] = $capability->label().($scoped > 0 ? " (+{$scoped} scoped)" : '');
            } elseif ($scoped > 0) {
                $parts[] = "{$capability->label()} ({$scoped} scoped)";
            }
        }

        return $parts === [] ? 'Read-only' : implode(', ', $parts);
    }

    /** Drop all memoized rules. */
    public function flush(): void
    {
        $this->rules = [];
    }

    private function matches(PermissionRule $rule, Target $target): bool
    {
        if ($rule->scope_type === ScopeType::Global) {
            return true;
        }

        if ($target->organization === null || $rule->organization !== $target->organization) {
            return false;
        }

        return match ($rule->scope_type) {
            ScopeType::Organization => true,
            ScopeType::Server => $target->serverId !== null && $rule->server_id === $target->serverId,
            ScopeType::Site => $target->siteId !== null && $rule->site_id === $target->siteId,
        };
    }

    private function isUsable(PersonalAccessToken $token): bool
    {
        return $token->exists
            && in_array('mcp', $token->abilities ?? [], true)
            && ($token->expires_at === null || $token->expires_at->isFuture());
    }

    /** @return list<string> */
    private function exposedOrganizations(PersonalAccessToken $token): array
    {
        if ($token->tokenable_type !== (new User)->getMorphClass()) {
            return [];
        }

        // Not memoized: un-exposing an organization must take effect immediately.
        $connection = ForgeConnection::query()->where('user_id', $token->tokenable_id)->first();

        return $connection?->exposedOrganizations() ?? [];
    }

    /** @return list<PermissionRule> */
    private function rulesFor(PersonalAccessToken $token, Capability $capability): array
    {
        if (! $token->exists) {
            return [];
        }

        $id = (int) $token->getKey();

        if (! isset($this->rules[$id])) {
            $grouped = [];

            foreach (PermissionRule::query()->where('personal_access_token_id', $id)->get() as $rule) {
                $grouped[$rule->capability->value][] = $rule;
            }

            $this->rules[$id] = $grouped;
        }

        return $this->rules[$id][$capability->value] ?? [];
    }

    /**
     * @template T of string|int
     *
     * @param  T|null  $value
     * @return T
     */
    private function require(string|int|null $value, ScopeType $scope, string $what): string|int
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("A {$scope->value} rule needs {$what}.");
        }

        return $value;
    }
}
