<?php

use App\Forge\ForgeClient;
use App\Mcp\AccessTokens;
use App\Models\PermissionRule;
use App\Models\TokenDefault;
use App\Models\User;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(fn () => ForgeClient::flushCache());

/** @param list<string> $exposed */
function permsSetup(array $exposed = ['acme'], bool $connected = true, bool $forgeDown = false): array
{
    $user = User::factory()->create();
    actingAs($user);

    if ($connected) {
        $user->forgeConnection()->create([
            'token' => 'forge_token_value', 'token_last_four' => 'alue', 'forge_user_name' => 'Ada',
            'organizations' => [['slug' => 'acme', 'name' => 'Acme'], ['slug' => 'other', 'name' => 'Other']],
            'exposed_organizations' => $exposed,
        ]);
    }

    $token = app(AccessTokens::class)->create($user, 'agent')->accessToken;

    $forgeDown && Http::fake(['forge.laravel.com/*' => Http::response([], 500)]);

    Http::fake([
        'forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(['data' => [
            ['id' => '42', 'type' => 'sites', 'attributes' => ['name' => 'example.com']],
        ]]),
        'forge.laravel.com/api/orgs/acme/servers*' => Http::response(['data' => [
            ['id' => '1', 'type' => 'servers', 'attributes' => ['name' => 'web-1']],
        ]]),
        'forge.laravel.com/api/orgs/other/*' => Http::response(['data' => [
            ['id' => '9', 'type' => 'servers', 'attributes' => ['name' => 'other-1']],
        ]]),
    ]);

    return [$user, $token];
}

function permsAllowed(PersonalAccessToken $token, Capability $c, ?Target $t = null): bool
{
    $engine = app(PermissionEngine::class);
    $engine->flush();

    return $engine->allows($token, $c, $t ?? new Target('acme', 1, 42));
}

it('toggles a global capability', function () {
    [, $token] = permsSetup();

    Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->set('global.deploy', true)
        ->call('saveGlobal')
        ->assertHasNoErrors();

    expect(permsAllowed($token, Capability::Deploy))->toBeTrue();

    Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->assertSet('global.deploy', true)
        ->set('global.deploy', false)
        ->call('saveGlobal');

    expect(permsAllowed($token, Capability::Deploy))->toBeFalse();
});

it('requires acknowledgement for risky capabilities server side', function () {
    [, $token] = permsSetup();

    Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->set('global.edit_env', true)
        ->call('saveGlobal')
        ->assertHasErrors(['ack.edit_env'])
        ->assertSee('I understand this lets agents change the .env file');

    expect(permsAllowed($token, Capability::EditEnv))->toBeFalse();

    Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->set('global.edit_env', true)
        ->set('ack.edit_env', true)
        ->call('saveGlobal')
        ->assertHasNoErrors();

    expect(permsAllowed($token, Capability::EditEnv))->toBeTrue();
});

it('sets server and site overrides with inherit/allow/deny', function () {
    [, $token] = permsSetup();
    $page = fn () => Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()]);

    $page()
        ->set('overrideOrg', 'acme')->set('overrideServer', '1')
        ->set('overrideCapability', 'deploy')->set('overrideState', 'allow')
        ->call('saveOverride')->assertHasNoErrors()
        ->assertSee('web-1');

    expect(permsAllowed($token, Capability::Deploy))->toBeTrue()
        ->and(permsAllowed($token, Capability::Deploy, new Target('acme', 2, 5)))->toBeFalse();

    $page()
        ->set('overrideOrg', 'acme')->set('overrideServer', '1')->set('overrideSite', '42')
        ->set('overrideCapability', 'deploy')->set('overrideState', 'deny')
        ->call('saveOverride')->assertHasNoErrors();

    expect(permsAllowed($token, Capability::Deploy))->toBeFalse();

    $page()
        ->set('overrideOrg', 'acme')->set('overrideServer', '1')->set('overrideSite', '42')
        ->set('overrideCapability', 'deploy')->set('overrideState', 'inherit')
        ->call('saveOverride')->assertHasNoErrors();

    expect(permsAllowed($token, Capability::Deploy))->toBeTrue();
});

it('sets an organization override', function () {
    [, $token] = permsSetup();

    Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->set('overrideOrg', 'acme')->set('overrideCapability', 'view_logs')->set('overrideState', 'deny')
        ->call('saveOverride')->assertHasNoErrors();

    expect(permsAllowed($token, Capability::ViewLogs))->toBeFalse();
});

it('removes an override', function () {
    [, $token] = permsSetup();
    app(PermissionEngine::class)->set($token, Capability::Deploy, ScopeType::Server, new Target('acme', 1), true);
    $rule = PermissionRule::query()->where('scope_type', 'server')->firstOrFail();

    Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])->call('removeOverride', $rule->id);

    expect(PermissionRule::query()->whereKey($rule->id)->exists())->toBeFalse();
});

it('does not offer restart_services or reboot_server at site level', function () {
    [, $token] = permsSetup();

    $page = Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->set('overrideOrg', 'acme')->set('overrideServer', '1')
        ->assertSeeHtml('data-override-option="restart_services"')
        ->assertSeeHtml('data-override-option="reboot_server"')
        ->set('overrideSite', '42')
        ->assertDontSeeHtml('data-override-option="restart_services"')
        ->assertDontSeeHtml('data-override-option="reboot_server"')
        ->assertSeeHtml('data-override-option="deploy"');

    $page->set('overrideCapability', 'restart_services')->set('overrideState', 'allow')
        ->call('saveOverride')->assertHasErrors(['overrideCapability']);

    expect(PermissionRule::query()->where('capability', 'restart_services')->exists())->toBeFalse();
});

it('requires acknowledgement when allowing a risky capability in an override', function () {
    [, $token] = permsSetup();

    $page = Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->set('overrideOrg', 'acme')->set('overrideServer', '1')
        ->set('overrideCapability', 'run_commands')->set('overrideState', 'allow')
        ->call('saveOverride')->assertHasErrors(['overrideAck']);

    expect(PermissionRule::query()->where('capability', 'run_commands')->exists())->toBeFalse();

    $page->set('overrideAck', true)->call('saveOverride')->assertHasNoErrors();
    expect(PermissionRule::query()->where('capability', 'run_commands')->exists())->toBeTrue();
});

it('rejects tampered organizations, servers and sites', function () {
    [, $token] = permsSetup();
    $page = fn () => Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->set('overrideCapability', 'deploy')->set('overrideState', 'allow');

    // Organization that is not exposed.
    $page()->set('overrideOrg', 'other')->call('saveOverride')->assertHasErrors(['overrideOrg']);
    $page()->set('overrideOrg', 'nope')->call('saveOverride')->assertHasErrors(['overrideOrg']);
    // Server not in the organization's server list.
    $page()->set('overrideOrg', 'acme')->set('overrideServer', '999')->call('saveOverride')->assertHasErrors(['overrideServer']);
    // Site not on that server.
    $page()->set('overrideOrg', 'acme')->set('overrideServer', '1')->set('overrideSite', '777')->call('saveOverride')->assertHasErrors(['overrideSite']);
    // Site without a server.
    $page()->set('overrideOrg', 'acme')->set('overrideSite', '42')->call('saveOverride')->assertHasErrors(['overrideServer']);
    // Unknown capability / state.
    $page()->set('overrideOrg', 'acme')->set('overrideCapability', 'bogus')->call('saveOverride')->assertHasErrors(['overrideCapability']);
    $page()->set('overrideOrg', 'acme')->set('overrideState', 'maybe')->call('saveOverride')->assertHasErrors(['overrideState']);

    expect(PermissionRule::query()->where('scope_type', '!=', 'global')->count())->toBe(0);
});

it('only offers exposed organizations', function () {
    [, $token] = permsSetup();

    Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->assertSeeHtml('data-override-org="acme"')
        ->assertDontSeeHtml('data-override-org="other"');
});

it('shows Forge errors inline', function () {
    [, $token] = permsSetup(forgeDown: true);

    Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->set('overrideOrg', 'acme')
        ->assertSee('Could not load servers');
});

it('warns when Forge is not connected', function () {
    [, $token] = permsSetup(connected: false);

    Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->assertSee('Connect Forge')
        ->assertSeeHtml(route('forge.connection'));
});

it('warns when no organization is exposed', function () {
    [, $token] = permsSetup(exposed: []);

    Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->assertSee('No organization is exposed')
        ->assertSeeHtml(route('forge.connection'));
});

it('returns 404 for another users token', function () {
    $other = User::factory()->create();
    $token = app(AccessTokens::class)->create($other, 'theirs')->accessToken;
    actingAs(User::factory()->create());

    get(route('tokens.permissions', $token->getKey()))->assertNotFound();
});

it('returns 404 for an unknown token', function () {
    actingAs(User::factory()->create());

    get(route('tokens.permissions', 999))->assertNotFound();
});

it('renders for the owner', function () {
    [, $token] = permsSetup();

    get(route('tokens.permissions', $token->getKey()))->assertOk()->assertSee('agent');
});

it('the configure_workers acknowledgement says it runs arbitrary commands, optionally as root', function () {
    [, $token] = permsSetup();

    Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()])
        ->set('global.configure_workers', true)
        ->call('saveGlobal')
        ->assertHasErrors(['ack.configure_workers'])
        ->assertSee('I understand this lets agents create, change and delete background processes (runs arbitrary commands, optionally as root).');
});

it('saves and clears per-token defaults validated against live Forge lists', function () {
    [, $token] = permsSetup();
    $page = fn () => Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()]);

    $page()
        ->assertSee('Project `.mcp.json` headers override these.')
        ->set('defaultOrg', 'acme')->set('defaultServer', '1')->set('defaultSite', '42')
        ->call('saveDefaults')->assertHasNoErrors();

    $row = TokenDefault::query()->sole();
    expect($row->personal_access_token_id)->toBe($token->id)->and($row->organization)->toBe('acme')
        ->and($row->server_id)->toBe(1)->and($row->site_id)->toBe(42);

    // The stored defaults are shown and prefill the form.
    $page()->assertSet('defaultServer', '1')->assertSee('acme / server web-1 (1) / site example.com (42)');

    $page()->call('clearDefaults');
    expect(TokenDefault::query()->count())->toBe(0);
});

it('rejects tampered default ids and unexposed organizations', function () {
    [, $token] = permsSetup();
    $page = fn () => Livewire::test('pages::tokens.permissions', ['token' => $token->getKey()]);

    $page()->set('defaultOrg', 'other')->set('defaultServer', '9')->call('saveDefaults')->assertHasErrors(['defaultOrg']);
    $page()->set('defaultOrg', 'acme')->set('defaultServer', '999')->call('saveDefaults')->assertHasErrors(['defaultServer']);
    $page()->set('defaultOrg', 'acme')->set('defaultServer', '1')->set('defaultSite', '777')->call('saveDefaults')->assertHasErrors(['defaultSite']);
    $page()->set('defaultOrg', 'acme')->set('defaultServer', '')->call('saveDefaults')->assertHasErrors(['defaultServer']);

    expect(TokenDefault::query()->count())->toBe(0);
});
