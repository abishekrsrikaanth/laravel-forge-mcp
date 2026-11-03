<?php

use App\Mcp\AccessTokens;
use App\Models\PermissionRule;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

function tokensUser(): User
{
    actingAs($user = User::factory()->create());

    return $user;
}

it('creates a token and shows the plaintext once', function () {
    $user = tokensUser();

    $component = Livewire::test('pages::tokens.index')
        ->set('name', 'Laptop agent')
        ->call('create')
        ->assertHasNoErrors();

    $token = $user->tokens()->firstOrFail();
    expect($token->name)->toBe('Laptop agent');

    $plain = $component->get('plainTextToken');
    expect($plain)->toStartWith($token->getKey().'|');
    $component->assertSee($plain)->assertSet('name', '');

    // Any next request clears it.
    $component->call('$refresh')->assertSet('plainTextToken', null)->assertDontSee($plain);

    get(route('tokens.index'))->assertOk()->assertDontSee($plain)->assertSee('Laptop agent');
});

it('requires a token name', function () {
    tokensUser();

    Livewire::test('pages::tokens.index')->set('name', '')->call('create')->assertHasErrors(['name']);
});

it('shows connection snippets', function () {
    config(['app.url' => 'https://forge-mcp.test']);
    tokensUser();

    $component = Livewire::test('pages::tokens.index')
        ->assertSee('claude mcp add --transport http forge https://forge-mcp.test/mcp --header "Authorization: Bearer <token>"')
        ->assertSee('mcpServers');

    $component->set('name', 'x')->call('create');
    $plain = $component->get('plainTextToken');

    $component->assertSee('claude mcp add --transport http forge https://forge-mcp.test/mcp --header "Authorization: Bearer '.$plain.'"');
});

it('revokes a token', function () {
    $user = tokensUser();
    $token = app(AccessTokens::class)->create($user, 'old')->accessToken;
    expect(PermissionRule::query()->where('personal_access_token_id', $token->getKey())->count())->toBeGreaterThan(0);

    Livewire::test('pages::tokens.index')->call('revoke', $token->getKey())->assertHasNoErrors();

    expect(PersonalAccessToken::find($token->getKey()))->toBeNull()
        ->and(PermissionRule::query()->where('personal_access_token_id', $token->getKey())->count())->toBe(0);
});

it('cannot revoke another users token', function () {
    $other = User::factory()->create();
    $token = app(AccessTokens::class)->create($other, 'theirs')->accessToken;
    tokensUser();

    expect(fn () => Livewire::test('pages::tokens.index')->call('revoke', $token->getKey()))->not->toThrow(Exception::class);
    expect(PersonalAccessToken::find($token->getKey()))->not->toBeNull();
});

it('lists last used and summary per token', function () {
    $user = tokensUser();
    $token = app(AccessTokens::class)->create($user, 'ci')->accessToken;
    $token->forceFill(['last_used_at' => now()->subHours(3)])->save();

    Livewire::test('pages::tokens.index')
        ->assertSee('ci')
        ->assertSee('Read-only')
        ->assertSee('3 hours ago');

    $fresh = app(AccessTokens::class)->create($user, 'never')->accessToken;
    Livewire::test('pages::tokens.index')->assertSee('Never used');
});

it('can copy permissions from an existing token', function () {
    $user = tokensUser();
    $source = app(AccessTokens::class)->create($user, 'src')->accessToken;
    PermissionRule::query()->create([
        'personal_access_token_id' => $source->getKey(), 'capability' => 'deploy', 'scope_type' => 'global', 'allowed' => true,
    ]);

    Livewire::test('pages::tokens.index')->set('name', 'copy')->set('copyFrom', (string) $source->getKey())->call('create');

    $copy = $user->tokens()->where('name', 'copy')->firstOrFail();
    expect(PermissionRule::query()->where('personal_access_token_id', $copy->getKey())->where('capability', 'deploy')->exists())->toBeTrue();
});

it('does not copy from another users token', function () {
    $other = User::factory()->create();
    $source = app(AccessTokens::class)->create($other, 'theirs')->accessToken;
    $user = tokensUser();

    Livewire::test('pages::tokens.index')->set('name', 'copy')->set('copyFrom', (string) $source->getKey())->call('create')->assertHasErrors(['copyFrom']);

    expect($user->tokens()->count())->toBe(0);
});

it('requires authentication', function () {
    get(route('tokens.index'))->assertRedirect(route('login'));
});
