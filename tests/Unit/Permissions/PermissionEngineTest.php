<?php

use App\Mcp\AccessTokens;
use App\Models\ForgeConnection;
use App\Models\PermissionRule;
use App\Models\User;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @param  list<string>  $exposed */
function peOwner(array $exposed = ['acme', 'beta']): User
{
    $user = User::factory()->create();
    ForgeConnection::query()->create([
        'user_id' => $user->id,
        'token' => 'forge-token',
        'token_last_four' => 'oken',
        'forge_user_name' => 'Ada',
        'organizations' => array_map(fn (string $slug) => ['slug' => $slug, 'name' => $slug], array_values(array_unique([...$exposed, 'gamma']))),
        'exposed_organizations' => $exposed,
    ]);

    return $user;
}

/** @param  list<string>  $exposed */
function peToken(array $exposed = ['acme', 'beta'], ?User $owner = null): PersonalAccessToken
{
    return app(AccessTokens::class)->create($owner ?? peOwner($exposed), 'Agent')->accessToken;
}

function engine(): PermissionEngine
{
    return app(PermissionEngine::class);
}

// Targets used throughout. Server 3 hosts sites 12 and 13; server 4 hosts site 20.
function site12(): Target
{
    return new Target('acme', 3, 12);
}

it('fresh token allows view and view_logs in exposed orgs and nothing else', function () {
    $token = peToken();

    foreach ([new Target('acme'), new Target('acme', 3), site12(), new Target('beta', 9, 90)] as $target) {
        foreach (Capability::cases() as $capability) {
            expect(engine()->allows($token, $capability, $target))
                ->toBe(in_array($capability, [Capability::View, Capability::ViewLogs], true), "{$capability->value} on {$target->describe()}");
        }
    }

    expect(PermissionRule::query()->where('personal_access_token_id', $token->id)->count())->toBe(2);
});

it('unexposed organization is always denied', function (Capability $capability) {
    $token = peToken();
    engine()->set($token, $capability, ScopeType::Global, new Target, true);
    engine()->set($token, $capability, ScopeType::Organization, new Target('gamma'), true);
    engine()->set($token, $capability, ScopeType::Server, new Target('gamma', 5), true);
    if ($capability->allowsSiteScope()) {
        engine()->set($token, $capability, ScopeType::Site, new Target('gamma', 5, 50), true);
    }

    expect(engine()->allows($token, $capability, new Target('gamma')))->toBeFalse()
        ->and(engine()->allows($token, $capability, new Target('gamma', 5)))->toBeFalse()
        ->and(engine()->allows($token, $capability, new Target('gamma', 5, 50)))->toBeFalse()
        ->and(engine()->allows($token, $capability, new Target('acme', 5, 50)))->toBeTrue();
})->with(fn () => array_map(fn (Capability $c) => [$c], Capability::cases()));

it('denies everything when the owner has no Forge connection', function () {
    $token = app(AccessTokens::class)->create(User::factory()->create(), 'Agent')->accessToken;

    expect(engine()->allows($token, Capability::View, site12()))->toBeFalse()
        ->and(engine()->allows($token, Capability::View, new Target))->toBeFalse()
        ->and(engine()->allowsAnywhere($token, Capability::View))->toBeFalse();
});

it('denies everything once an organization is no longer exposed', function () {
    $owner = peOwner(['acme']);
    $token = peToken(owner: $owner);
    expect(engine()->allows($token, Capability::View, site12()))->toBeTrue();

    $owner->forgeConnection->update(['exposed_organizations' => []]);

    expect(engine()->allows($token, Capability::View, site12()))->toBeFalse()
        ->and(engine()->allows($token, Capability::View, new Target))->toBeFalse();
});

/*
 * Resolution order: site > server > organization > global; no matching rule = denied.
 * Each case: rules as [ScopeType, Target, allowed], then [Target, expected] checks.
 */
dataset('resolution', [
    'no rules denies' => [
        [],
        [[site12(), false], [new Target('acme', 3), false], [new Target('acme'), false], [new Target, false]],
    ],
    'global allow applies everywhere exposed' => [
        [[ScopeType::Global, new Target, true]],
        [[site12(), true], [new Target('acme', 4, 20), true], [new Target('acme', 3), true], [new Target('beta'), true], [new Target, true]],
    ],
    'global deny + site allow => allowed only on that site' => [
        [[ScopeType::Global, new Target, false], [ScopeType::Site, site12(), true]],
        [[site12(), true], [new Target('acme', 3, 13), false], [new Target('acme', 3), false], [new Target('acme'), false], [new Target('acme', 4, 20), false], [new Target, false]],
    ],
    'global allow + server deny => denied on that server and its sites' => [
        [[ScopeType::Global, new Target, true], [ScopeType::Server, new Target('acme', 3), false]],
        [[new Target('acme', 3), false], [site12(), false], [new Target('acme', 3, 13), false], [new Target('acme', 4), true], [new Target('acme', 4, 20), true], [new Target('acme'), true]],
    ],
    'global allow + server deny + site allow => only that site re-allowed' => [
        [[ScopeType::Global, new Target, true], [ScopeType::Server, new Target('acme', 3), false], [ScopeType::Site, site12(), true]],
        [[site12(), true], [new Target('acme', 3, 13), false], [new Target('acme', 3), false], [new Target('acme', 4, 20), true]],
    ],
    'org deny + server allow => allowed on that server' => [
        [[ScopeType::Organization, new Target('acme'), false], [ScopeType::Server, new Target('acme', 3), true]],
        [[new Target('acme', 3), true], [site12(), true], [new Target('acme', 4), false], [new Target('acme', 4, 20), false], [new Target('acme'), false]],
    ],
    'global allow + org deny => org denied, other org allowed' => [
        [[ScopeType::Global, new Target, true], [ScopeType::Organization, new Target('acme'), false]],
        [[site12(), false], [new Target('acme'), false], [new Target('beta', 9, 90), true], [new Target('beta'), true]],
    ],
    'global deny + org allow => org allowed only' => [
        [[ScopeType::Global, new Target, false], [ScopeType::Organization, new Target('acme'), true]],
        [[new Target('acme'), true], [site12(), true], [new Target('beta'), false], [new Target('beta', 9, 90), false]],
    ],
    'org allow + site deny => only that site denied' => [
        [[ScopeType::Organization, new Target('acme'), true], [ScopeType::Site, site12(), false]],
        [[site12(), false], [new Target('acme', 3, 13), true], [new Target('acme', 3), true]],
    ],
    'server allow + site deny => only that site denied' => [
        [[ScopeType::Server, new Target('acme', 3), true], [ScopeType::Site, site12(), false]],
        [[site12(), false], [new Target('acme', 3, 13), true], [new Target('acme', 3), true]],
    ],
    'site allow alone => only that site' => [
        [[ScopeType::Site, site12(), true]],
        [[site12(), true], [new Target('acme', 3, 13), false], [new Target('acme', 3), false], [new Target('acme'), false]],
    ],
    'server allow does not apply to the organization or other servers' => [
        [[ScopeType::Server, new Target('acme', 3), true]],
        [[new Target('acme', 3), true], [new Target('acme', 3, 13), true], [new Target('acme'), false], [new Target('acme', 4), false], [new Target('acme', 4, 20), false]],
    ],
    'server rule needs the same organization' => [
        [[ScopeType::Server, new Target('beta', 3), true]],
        [[new Target('beta', 3), true], [new Target('acme', 3), false], [site12(), false]],
    ],
    'site rule needs the same organization' => [
        [[ScopeType::Site, new Target('beta', 3, 12), true]],
        [[new Target('beta', 3, 12), true], [site12(), false]],
    ],
    'org rule needs the same organization' => [
        [[ScopeType::Organization, new Target('beta'), true]],
        [[new Target('beta', 9, 90), true], [site12(), false], [new Target('acme'), false]],
    ],
    'site rule matches on organization + site id regardless of server' => [
        [[ScopeType::Site, site12(), true]],
        [[new Target('acme', 4, 12), true], [new Target('acme', 3, 12), true]],
    ],
    'site deny overrides global allow' => [
        [[ScopeType::Global, new Target, true], [ScopeType::Site, site12(), false]],
        [[site12(), false], [new Target('acme', 3, 13), true]],
    ],
    'org-less target with server or site id is denied' => [
        [[ScopeType::Global, new Target, true]],
        [[new Target(null, 3), false], [new Target(null, 3, 12), false], [new Target, true]],
    ],
    'org-less target only consults global rules' => [
        [[ScopeType::Organization, new Target('acme'), true], [ScopeType::Server, new Target('acme', 3), true]],
        [[new Target, false], [new Target('acme'), true]],
    ],
]);

it('most specific rule wins', function (array $rules, array $checks) {
    $token = peToken();
    foreach ($rules as [$scope, $target, $allowed]) {
        engine()->set($token, Capability::Deploy, $scope, $target, $allowed);
    }

    foreach ($checks as [$target, $expected]) {
        expect(engine()->allows($token, Capability::Deploy, $target))->toBe($expected, $target->describe());
    }
})->with('resolution');

it('rejects site targets without a server id', function (?string $organization) {
    new Target($organization, null, 12);
})->throws(InvalidArgumentException::class)->with(['acme', null]);

it('denies a site target without a server id even if one is built around the constructor', function () {
    $token = peToken();
    engine()->set($token, Capability::Deploy, ScopeType::Global, new Target, true);
    engine()->set($token, Capability::Deploy, ScopeType::Server, new Target('acme', 3), false);
    engine()->set($token, Capability::Deploy, ScopeType::Site, site12(), true);

    // The old bypass: global allow + server 3 deny, site 12 (on server 3) asked for without its server.
    $smuggled = (new ReflectionClass(Target::class))->newInstanceWithoutConstructor();
    (function () {
        $this->organization = 'acme';
        $this->serverId = null;
        $this->siteId = 13;
    })->call($smuggled);

    $alsoSmuggled = unserialize(serialize(new Target('acme', 3, 12)), ['allowed_classes' => [Target::class]]);
    $alsoSmuggled = unserialize(str_replace('i:3;', 'N;', serialize($alsoSmuggled)), ['allowed_classes' => [Target::class]]);

    expect($smuggled->siteId)->toBe(13)->and($smuggled->serverId)->toBeNull()
        ->and($alsoSmuggled->siteId)->toBe(12)->and($alsoSmuggled->serverId)->toBeNull()
        ->and(engine()->allows($token, Capability::Deploy, $smuggled))->toBeFalse()
        ->and(engine()->allows($token, Capability::Deploy, $alsoSmuggled))->toBeFalse()
        ->and(engine()->allows($token, Capability::View, $smuggled))->toBeFalse()
        ->and(engine()->allows($token, Capability::Deploy, new Target('acme', 3, 13)))->toBeFalse()
        ->and(engine()->allows($token, Capability::Deploy, site12()))->toBeTrue();
});

it('compares organizations case-sensitively', function () {
    $token = peToken(['acme', 'ACME']);
    engine()->set($token, Capability::Deploy, ScopeType::Organization, new Target('acme'), true);
    engine()->set($token, Capability::Deploy, ScopeType::Server, new Target('acme', 3), false);

    expect(engine()->allows($token, Capability::Deploy, new Target('acme', 4)))->toBeTrue()
        ->and(engine()->allows($token, Capability::Deploy, new Target('ACME', 4)))->toBeFalse()
        ->and(engine()->allows($token, Capability::View, new Target('Acme')))->toBeFalse();
});

it('denies when rules at the same specificity conflict', function () {
    $token = peToken();
    engine()->set($token, Capability::Deploy, ScopeType::Global, new Target, true);
    PermissionRule::query()->create(['personal_access_token_id' => $token->id, 'capability' => Capability::Deploy, 'scope_type' => ScopeType::Site, 'organization' => 'acme', 'server_id' => 3, 'site_id' => 12, 'allowed' => true]);
    PermissionRule::query()->create(['personal_access_token_id' => $token->id, 'capability' => Capability::Deploy, 'scope_type' => ScopeType::Site, 'organization' => 'acme', 'server_id' => 4, 'site_id' => 12, 'allowed' => false]);

    expect(engine()->allows($token, Capability::Deploy, site12()))->toBeFalse();
});

it('restart_services and reboot_server ignore site scope', function (Capability $capability) {
    $token = peToken();

    expect(fn () => engine()->set($token, $capability, ScopeType::Site, site12(), true))
        ->toThrow(InvalidArgumentException::class)
        ->and(engine()->allows($token, $capability, site12()))->toBeFalse();

    // A site rule smuggled into the table is never consulted.
    PermissionRule::query()->create(['personal_access_token_id' => $token->id, 'capability' => $capability, 'scope_type' => ScopeType::Site, 'organization' => 'acme', 'server_id' => 3, 'site_id' => 12, 'allowed' => true]);
    expect(engine()->allows($token, $capability, site12()))->toBeFalse()
        ->and(engine()->allowsAnywhere($token, $capability))->toBeFalse();

    engine()->set($token, $capability, ScopeType::Server, new Target('acme', 3), true);
    expect(engine()->allows($token, $capability, site12()))->toBeTrue()
        ->and(engine()->allows($token, $capability, new Target('acme', 3)))->toBeTrue()
        ->and(engine()->allows($token, $capability, new Target('acme', 4)))->toBeFalse();

    // Server deny under a global allow wins over the smuggled site allow.
    engine()->set($token, $capability, ScopeType::Global, new Target, true);
    engine()->set($token, $capability, ScopeType::Server, new Target('acme', 3), false);
    PermissionRule::query()->where('scope_type', 'site')->update(['allowed' => true]);
    expect(engine()->allows($token, $capability, site12()))->toBeFalse()
        ->and(engine()->allows($token, $capability, new Target('acme', 4, 20)))->toBeTrue();

    engine()->set($token, $capability, ScopeType::Server, new Target('acme', 3), null);
    engine()->set($token, $capability, ScopeType::Organization, new Target('acme'), false);
    expect(engine()->allows($token, $capability, site12()))->toBeFalse()
        ->and(engine()->allows($token, $capability, new Target('beta', 9, 90)))->toBeTrue();
})->with([Capability::RestartServices, Capability::RebootServer]);

it('rules are isolated per token', function () {
    $owner = peOwner();
    $a = peToken(owner: $owner);
    $b = peToken(owner: $owner);

    engine()->set($a, Capability::Deploy, ScopeType::Global, new Target, true);
    engine()->set($b, Capability::View, ScopeType::Global, new Target, false);

    expect(engine()->allows($a, Capability::Deploy, site12()))->toBeTrue()
        ->and(engine()->allows($b, Capability::Deploy, site12()))->toBeFalse()
        ->and(engine()->allows($a, Capability::View, site12()))->toBeTrue()
        ->and(engine()->allows($b, Capability::View, site12()))->toBeFalse()
        ->and(engine()->allows($a, Capability::Deploy, site12()))->toBeTrue();
});

it('sees rules set after an earlier lookup in the same request', function () {
    $token = peToken();
    expect(engine()->allows($token, Capability::Deploy, site12()))->toBeFalse();

    engine()->set($token, Capability::Deploy, ScopeType::Site, site12(), true);
    expect(engine()->allows($token, Capability::Deploy, site12()))->toBeTrue();

    engine()->set($token, Capability::Deploy, ScopeType::Site, site12(), false);
    expect(engine()->allows($token, Capability::Deploy, site12()))->toBeFalse();
});

it('denies tokens without the mcp ability', function () {
    $owner = peOwner();
    $token = $owner->createToken('web', ['other'])->accessToken;
    engine()->set($token, Capability::View, ScopeType::Global, new Target, true);

    expect(engine()->allows($token, Capability::View, site12()))->toBeFalse()
        ->and(engine()->allowsAnywhere($token, Capability::View))->toBeFalse();
});

it('allowsAnywhere false when every rule denies, true when one site allows', function () {
    $token = peToken();
    expect(engine()->allowsAnywhere($token, Capability::Deploy))->toBeFalse()
        ->and(engine()->allowsAnywhere($token, Capability::View))->toBeTrue();

    engine()->set($token, Capability::Deploy, ScopeType::Global, new Target, false);
    engine()->set($token, Capability::Deploy, ScopeType::Server, new Target('acme', 3), false);
    expect(engine()->allowsAnywhere($token, Capability::Deploy))->toBeFalse();

    engine()->set($token, Capability::Deploy, ScopeType::Site, site12(), true);
    expect(engine()->allowsAnywhere($token, Capability::Deploy))->toBeTrue();
});

it('allowsAnywhere ignores grants in unexposed organizations', function () {
    $token = peToken();
    engine()->set($token, Capability::Deploy, ScopeType::Site, new Target('gamma', 5, 50), true);

    expect(engine()->allowsAnywhere($token, Capability::Deploy))->toBeFalse();
});

it('set with null removes the rule', function () {
    $token = peToken();
    engine()->set($token, Capability::Deploy, ScopeType::Server, new Target('acme', 3), true);
    engine()->set($token, Capability::Deploy, ScopeType::Server, new Target('acme', 3), false);
    expect(PermissionRule::query()->where('capability', 'deploy')->count())->toBe(1)
        ->and(engine()->allows($token, Capability::Deploy, site12()))->toBeFalse();

    engine()->set($token, Capability::Deploy, ScopeType::Server, new Target('acme', 3), null);
    expect(PermissionRule::query()->where('capability', 'deploy')->count())->toBe(0);

    engine()->set($token, Capability::View, ScopeType::Global, new Target, null);
    expect(engine()->allows($token, Capability::View, site12()))->toBeFalse();
});

it('stores only the columns that belong to the scope', function () {
    $token = peToken();
    engine()->set($token, Capability::Deploy, ScopeType::Global, site12(), true);
    engine()->set($token, Capability::Deploy, ScopeType::Organization, site12(), true);
    engine()->set($token, Capability::Deploy, ScopeType::Server, site12(), true);

    $rules = PermissionRule::query()->where('capability', 'deploy')->orderBy('id')->get(['scope_type', 'organization', 'server_id', 'site_id'])->toArray();

    expect($rules)->toBe([
        ['scope_type' => 'global', 'organization' => null, 'server_id' => null, 'site_id' => null],
        ['scope_type' => 'organization', 'organization' => 'acme', 'server_id' => null, 'site_id' => null],
        ['scope_type' => 'server', 'organization' => 'acme', 'server_id' => 3, 'site_id' => null],
    ]);
});

it('rejects rules missing the identifiers their scope needs', function (ScopeType $scope, Target $target) {
    engine()->set(peToken(), Capability::Deploy, $scope, $target, true);
})->throws(InvalidArgumentException::class)->with([
    'org without slug' => [ScopeType::Organization, new Target],
    'server without org' => [ScopeType::Server, new Target(null, 3)],
    'server without id' => [ScopeType::Server, new Target('acme')],
    'site without org' => [ScopeType::Site, new Target(null, 3, 12)],
    'site without id' => [ScopeType::Site, new Target('acme', 3)],
]);

it('summary describes the token', function () {
    $token = peToken();
    expect(engine()->summary($token))->toBe('Read-only');

    engine()->set($token, Capability::Deploy, ScopeType::Global, new Target, true);
    engine()->set($token, Capability::EditEnv, ScopeType::Site, site12(), true);
    engine()->set($token, Capability::EditEnv, ScopeType::Server, new Target('acme', 4), true);
    engine()->set($token, Capability::RunCommands, ScopeType::Site, site12(), false);

    expect(engine()->summary($token))->toBe('Deploy, Edit .env (2 scoped)');

    engine()->set($token, Capability::Deploy, ScopeType::Organization, new Target('acme'), true);
    expect(engine()->summary($token))->toBe('Deploy (+1 scoped), Edit .env (2 scoped)');
});
