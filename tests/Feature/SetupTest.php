<?php

use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

it('redirects every web route to setup when no user exists', function () {
    get('/')->assertRedirect(route('setup'));
    get('/login')->assertRedirect(route('setup'));
});

it('does not redirect the mcp endpoint before setup', function () {
    $response = postJson('/mcp');

    expect($response->isRedirect())->toBeFalse();
    expect($response->getStatusCode())->toBe(401);
});

it('renders the setup page when no user exists', function () {
    get(route('setup'))->assertOk();
});

it('creates the owner and logs them in', function () {
    Livewire::test('pages::setup')
        ->set('name', 'Owner')
        ->set('email', 'owner@example.com')
        ->set('password', 'password-123!XY')
        ->set('password_confirmation', 'password-123!XY')
        ->call('createOwner')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    expect(User::ownerExists())->toBeTrue();
    $this->assertAuthenticated();
});

it('returns 404 for setup once an owner exists', function () {
    User::factory()->create();

    get('/setup')->assertNotFound();
});

it('has no registration route', function () {
    get('/register')->assertNotFound();
});

it('rejects setup with invalid input', function () {
    Livewire::test('pages::setup')
        ->set('name', 'Owner')
        ->set('email', 'owner@example.com')
        ->set('password', 'password-123!XY')
        ->set('password_confirmation', 'different')
        ->call('createOwner')
        ->assertHasErrors(['password']);

    expect(User::query()->count())->toBe(0);
});
