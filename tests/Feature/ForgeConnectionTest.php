<?php

use App\Forge\ForgeClient;
use App\Forge\ForgeClientFactory;
use App\Forge\ForgeNotConnectedException;
use App\Models\ForgeConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportComputed\CannotCallComputedDirectlyException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

const CONN_TOKEN = 'forge_abcdefghijklmnopqrstuvwxyz0123456789wxyz';

function connFakeForge(array $orgs): void
{
    Http::fake([
        'forge.laravel.com/api/me*' => Http::response(['data' => ['id' => '1', 'type' => 'users', 'attributes' => ['name' => 'Ada', 'email' => 'ada@example.com']]]),
        'forge.laravel.com/api/orgs*' => Http::response(['data' => array_map(
            fn (string $slug) => ['id' => '1', 'type' => 'organizations', 'attributes' => ['name' => ucfirst($slug), 'slug' => $slug]],
            $orgs,
        )]),
    ]);
}

function connect(array $orgs = ['acme']): User
{
    connFakeForge($orgs);
    $user = User::factory()->create();
    actingAs($user);
    Livewire::test('pages::forge')->set('token', CONN_TOKEN)->call('connect')->assertHasNoErrors();

    return $user;
}

it('validates and stores the token encrypted', function () {
    connFakeForge(['acme']);
    actingAs($user = User::factory()->create());

    Livewire::test('pages::forge')
        ->set('token', CONN_TOKEN)
        ->call('connect')
        ->assertHasNoErrors()
        ->assertSee('•••• wxyz')
        ->assertDontSee(CONN_TOKEN)
        ->assertSet('token', '');

    $raw = DB::table('forge_connections')->where('user_id', $user->id)->first();
    expect($raw->token)->not->toBe(CONN_TOKEN)->not->toContain(CONN_TOKEN)
        ->and($raw->token_last_four)->toBe('wxyz')
        ->and($raw->forge_user_name)->toBe('Ada')
        ->and($user->forgeConnection->token)->toBe(CONN_TOKEN);

    get(route('forge.connection'))->assertOk()->assertSee('•••• wxyz')->assertDontSee(CONN_TOKEN);
});

it('preselects the only organization', function () {
    expect(connect(['acme'])->forgeConnection->exposed_organizations)->toBe(['acme']);
});

it('preselects nothing with several organizations', function () {
    expect(connect(['acme', 'beta'])->forgeConnection->exposed_organizations)->toBe([]);
});

it('rejects an invalid token', function () {
    Http::fake(['forge.laravel.com/api/me*' => Http::response([], 401)]);
    actingAs(User::factory()->create());

    Livewire::test('pages::forge')
        ->set('token', CONN_TOKEN)
        ->call('connect')
        ->assertHasErrors(['token'])
        ->assertSee('Forge rejected this token.')
        ->assertDontSee(CONN_TOKEN);

    expect(ForgeConnection::query()->count())->toBe(0);
});

it('updates exposed organizations', function () {
    $user = connect(['acme', 'beta']);
    (new ForgeClient(CONN_TOKEN))->organizations();
    Http::assertSentCount(3);

    Livewire::test('pages::forge')->set('exposed', ['beta'])->call('saveExposure')->assertHasNoErrors();

    $connection = $user->fresh()->forgeConnection;
    expect($connection->exposed_organizations)->toBe(['beta'])
        ->and($connection->exposes('beta'))->toBeTrue()
        ->and($connection->exposes('acme'))->toBeFalse()
        ->and($connection->exposedOrganizations())->toBe(['beta']);

    (new ForgeClient(CONN_TOKEN))->organizations();
    Http::assertSentCount(4);
});

it('ignores organizations that are not connected when saving exposure', function () {
    $user = connect(['acme', 'beta']);

    Livewire::test('pages::forge')->set('exposed', ['beta', 'evil'])->call('saveExposure');

    expect($user->fresh()->forgeConnection->exposed_organizations)->toBe(['beta']);
});

it('disconnects', function () {
    connect();

    Livewire::test('pages::forge')->call('disconnect')->assertSee('Connect to Forge');

    expect(ForgeConnection::query()->count())->toBe(0);
});

it('builds a client from the stored token and throws when not connected', function () {
    $user = connect();
    expect(app(ForgeClientFactory::class)->forUser($user))->toBeInstanceOf(ForgeClient::class);

    $other = User::factory()->create();
    app(ForgeClientFactory::class)->forUser($other);
})->throws(ForgeNotConnectedException::class);

it('lists required Forge scopes per capability', function () {
    actingAs(User::factory()->create());

    $page = get(route('forge.connection'))->assertOk();
    foreach (['server:view', 'server:manage-logs', 'site:manage-deploys', 'server:manage-services', 'server:create-daemons', 'server:delete-daemons', 'site:manage-environment', 'site:manage-commands'] as $scope) {
        $page->assertSee($scope);
    }
    $page->assertSee('Reading .env for secret scrubbing needs server:view.')
        ->assertSee('Configure workers')
        ->assertSee('Reboot server')
        ->assertSee('Maintenance mode');
});

it('clears the typed token when validation fails', function () {
    actingAs(User::factory()->create());

    Livewire::test('pages::forge')
        ->set('token', 'zq7Kp2x')
        ->call('connect')
        ->assertHasErrors(['token' => 'min'])
        ->assertSet('token', '')
        ->assertDontSee('zq7Kp2x');

    Http::assertNothingSent();
});

it('validates the trimmed token', function () {
    actingAs(User::factory()->create());

    Livewire::test('pages::forge')
        ->set('token', '   zq7Kp2   ')
        ->call('connect')
        ->assertHasErrors(['token' => 'min'])
        ->assertSet('token', '');

    Http::assertNothingSent();
});

it('clears the typed token when Forge is unreachable', function () {
    Http::fake(['forge.laravel.com/*' => fn () => throw new ConnectionException('down')]);
    actingAs(User::factory()->create());

    Livewire::test('pages::forge')
        ->set('token', CONN_TOKEN)
        ->call('connect')
        ->assertHasErrors(['token'])
        ->assertSet('token', '')
        ->assertDontSee(CONN_TOKEN);
});

it('does not expose the connection or scope list to the client', function () {
    connect();

    $component = Livewire::test('pages::forge');
    expect(array_keys($component->instance()->all()))->not->toContain('scopes')->not->toContain('connection');

    expect(fn () => $component->call('connection'))->toThrow(CannotCallComputedDirectlyException::class)
        ->and(fn () => $component->set('scopes', ['evil' => 'x']))->toThrow('Public property [$scopes] not found');
});

it('requires auth', function () {
    User::factory()->create();

    get(route('forge.connection'))->assertRedirect(route('login'));
});
