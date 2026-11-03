<?php

use App\Models\AuditEntry;
use App\Models\ForgeConnection;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

function auditUser(): User
{
    actingAs($user = User::factory()->create());

    return $user;
}

function auditEntry(User $user, array $attrs = []): AuditEntry
{
    return AuditEntry::create($attrs + [
        'user_id' => $user->id,
        'token_name' => 'Laptop',
        'tool' => 'list_servers',
        'outcome' => AuditEntry::SUCCESS,
        'summary' => 'Listed servers',
        'arguments' => [],
        'duration_ms' => 12,
        'created_at' => now(),
    ]);
}

it('requires authentication', function () {
    get(route('audit.index'))->assertRedirect(route('login'));
});

it('lists entries newest first and only the owners', function () {
    $user = auditUser();
    auditEntry($user, ['summary' => 'older entry', 'created_at' => now()->subHour()]);
    auditEntry($user, ['summary' => 'newer entry']);
    auditEntry(User::factory()->create(), ['summary' => 'foreign entry']);

    Livewire::test('pages::audit.index')
        ->assertSeeInOrder(['newer entry', 'older entry'])
        ->assertDontSee('foreign entry');
});

it('filters by tool, outcome and token name', function () {
    $user = auditUser();
    auditEntry($user, ['tool' => 'list_servers', 'summary' => 'row-servers']);
    auditEntry($user, ['tool' => 'deploy_site', 'outcome' => AuditEntry::DENIED, 'summary' => 'row-deploy']);
    auditEntry($user, ['token_name' => 'Revoked CI', 'personal_access_token_id' => null, 'summary' => 'row-ci']);

    Livewire::test('pages::audit.index')
        ->set('tool', 'deploy_site')->assertSee('row-deploy')->assertDontSee('row-servers')
        ->set('tool', '')->set('outcome', 'denied')->assertSee('row-deploy')->assertDontSee('row-ci')
        ->set('outcome', '')->set('token', 'Revoked CI')->assertSee('row-ci')->assertDontSee('row-servers');
});

it('ignores unknown filter values and other users values', function () {
    $user = auditUser();
    auditEntry($user, ['summary' => 'row-mine']);
    auditEntry(User::factory()->create(), ['tool' => 'secret_tool', 'token_name' => 'theirs']);

    Livewire::test('pages::audit.index')
        ->set('outcome', "x' OR 1=1 --")->assertSet('outcome', '')->assertSee('row-mine')
        ->set('tool', 'secret_tool')->assertSet('tool', '')->assertSee('row-mine')
        ->set('token', 'theirs')->assertSet('token', '')->assertSee('row-mine');
});

it('paginates 50 per page', function () {
    $user = auditUser();
    foreach (range(1, 55) as $i) {
        auditEntry($user, ['summary' => sprintf('entry-%02d', $i), 'created_at' => now()->subMinutes(60 - $i)]);
    }

    Livewire::test('pages::audit.index')
        ->assertSee('entry-55')->assertSee('entry-06')->assertDontSee('entry-05')
        ->call('gotoPage', 2)->assertSee('entry-05')->assertDontSee('entry-55');
});

it('shows arguments as pretty JSON', function () {
    $user = auditUser();
    auditEntry($user, ['arguments' => ['server' => 'web-1', 'nested' => ['a' => 1]]]);

    Livewire::test('pages::audit.index')->assertSee('&quot;server&quot;: &quot;web-1&quot;', false)->assertSee('&quot;nested&quot;: {', false);
});

it('shows a connect call to action on the dashboard without a connection', function () {
    auditUser();

    get(route('dashboard'))->assertOk()->assertSee('Connect Forge')->assertSee(route('forge.connection'));
});

it('shows last_error on the dashboard with a reconnect link', function () {
    $user = auditUser();
    ForgeConnection::create([
        'user_id' => $user->id, 'token' => 'forge_abcdefghijklmnopqrstuvwxyz', 'token_last_four' => 'wxyz',
        'forge_user_name' => 'Ada', 'organizations' => [], 'exposed_organizations' => [],
        'last_error' => 'Forge rejected the token (401)',
    ]);

    get(route('dashboard'))->assertOk()->assertSee('Forge rejected the token (401)')->assertSee('Reconnect');
});

it('shows the token count and the last 10 audit entries on the dashboard', function () {
    $user = auditUser();
    $user->createToken('one');
    $user->createToken('two');
    foreach (range(1, 12) as $i) {
        auditEntry($user, ['summary' => sprintf('dash-%02d', $i), 'created_at' => now()->subMinutes(20 - $i)]);
    }

    get(route('dashboard'))->assertOk()
        ->assertSee('dash-12')->assertSee('dash-03')->assertDontSee('dash-02')
        ->assertSee(route('audit.index'))
        ->assertSeeHtml('data-test="active-tokens">2<');
});
