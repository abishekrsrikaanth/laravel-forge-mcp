<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\GetSiteEnv;
use App\Mcp\Tools\SetSiteEnvValues;
use App\Models\AuditEntry;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const ENV_FIXTURE = "# App settings\nAPP_NAME=Alpha\nAPP_KEY=base64:oldAppKeyValue1234567890abcdef\n\n# Database\nDB_PASSWORD=old-db-password-9911\nMAIL_FROM=hello@example.com\n";

const ENV_NEW_VALUE = 'zq-Distinctive-NewValue-7731';

beforeEach(function () {
    ForgeClient::flushCache();
    Cache::flush();
});

/**
 * Fake Forge for site 12 on server 1. GET environment returns $contents; PUT environment is answered
 * by $put (default 200) and recorded.
 *
 * @param  (Closure(HttpRequest): mixed)|null  $put
 */
function envFake(string $contents = ENV_FIXTURE, ?Closure $put = null): void
{
    Http::fake([
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/environment' => function (HttpRequest $r) use ($contents, $put) {
            if ($r->method() === 'PUT') {
                return $put !== null ? $put($r) : Http::response('', 200);
            }

            return Http::response(['data' => ['id' => '12', 'type' => 'environment', 'attributes' => ['content' => $contents]]]);
        },
        'forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 12, 'name' => 'alpha.test', 'server_id' => 1],
        ])),
        'forge.laravel.com/api/orgs/acme/servers*' => Http::response(mcpForgeCollection('servers', [['id' => 1, 'name' => 'web-1']])),
    ]);
}

function envUser(array $grants): object
{
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user, $grants));

    return $user;
}

/** The body of the PUT environment request, or null when none was sent. */
function envPut(): ?array
{
    $found = null;
    Http::assertSent(function (HttpRequest $r) use (&$found) {
        if ($r->method() === 'PUT' && str_ends_with($r->url(), '/sites/12/environment')) {
            $found = $r->data();
        }

        return true;
    });

    return $found;
}

function envAssertNoPut(): void
{
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PUT');
}

/** Every column of every audit row, as one string. */
function envAuditDump(): string
{
    return json_encode(DB::table('audit_entries')->get()->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Collect every log message and its context. */
function envCaptureLogs(): ArrayObject
{
    $logs = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $e) use ($logs) {
        $logs[] = $e->level.' '.$e->message.' '.json_encode($e->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    });

    return $logs;
}

$viewEnv = [Capability::ViewEnv, ScopeType::Global, new Target, true];
$editEnv = [Capability::EditEnv, ScopeType::Global, new Target, true];

it('get_site_env returns unscrubbed contents and audits without them', function () use ($viewEnv) {
    envFake(ENV_FIXTURE.'FORGE_LEAK='.MCP_FORGE_TOKEN."\n");
    $user = envUser([$viewEnv]);

    ForgeServer::actingAs($user)->tool(GetSiteEnv::class, ['server_id' => 1, 'site_id' => 12])
        ->assertOk()
        ->assertSee('DB_PASSWORD=old-db-password-9911')
        ->assertSee('APP_KEY=base64:oldAppKeyValue1234567890abcdef')
        ->assertSee('# Database')
        ->assertSee('FORGE_LEAK=[REDACTED:FORGE_API_TOKEN]')
        ->assertDontSee(MCP_FORGE_TOKEN);

    $entry = AuditEntry::query()->sole();
    expect($entry->outcome)->toBe('success')
        ->and($entry->summary)->toBe('Read .env (5 keys)')
        ->and($entry->site_id)->toBe(12);

    expect(envAuditDump())->not->toContain('old-db-password-9911')
        ->not->toContain('oldAppKeyValue1234567890abcdef')
        ->not->toContain('hello@example.com');
});

it('get_site_env needs view_env on the site', function () {
    envFake();
    $user = envUser([[Capability::ViewEnv, ScopeType::Site, new Target('acme', 1, 13), true]]);

    ForgeServer::actingAs($user)->tool(GetSiteEnv::class, ['server_id' => 1, 'site_id' => 12])
        ->assertHasErrors(['not permitted'])
        ->assertDontSee('old-db-password-9911');

    expect(AuditEntry::query()->sole()->outcome)->toBe('denied');
});

it('set_site_env_values sends merged file preserving comments', function () use ($editEnv) {
    envFake();
    $user = envUser([$editEnv]);

    ForgeServer::actingAs($user)->tool(SetSiteEnvValues::class, [
        'server_id' => 1,
        'site_id' => 12,
        'set' => ['DB_PASSWORD' => 'new-db-password-1234', 'QUEUE_CONNECTION' => 'redis'],
        'remove' => ['MAIL_FROM'],
    ])->assertOk()
        ->assertSee('Updated .env for alpha.test. Added: QUEUE_CONNECTION. Changed: DB_PASSWORD. Removed: MAIL_FROM.');

    expect(envPut())->toBe(['environment' => "# App settings\nAPP_NAME=Alpha\nAPP_KEY=base64:oldAppKeyValue1234567890abcdef\n\n# Database\nDB_PASSWORD=new-db-password-1234\nQUEUE_CONNECTION=redis\n"]);

    $entry = AuditEntry::query()->sole();
    expect($entry->outcome)->toBe('success')
        ->and($entry->summary)->toBe('Updated .env for site 12. Added: QUEUE_CONNECTION. Changed: DB_PASSWORD. Removed: MAIL_FROM.')
        ->and($entry->arguments['set'])->toBe(['DB_PASSWORD' => '[REDACTED]', 'QUEUE_CONNECTION' => '[REDACTED]'])
        ->and($entry->arguments['remove'])->toBe(['MAIL_FROM']);
});

it('new values never appear in the response or audit table', function () use ($editEnv) {
    envFake();
    $logs = envCaptureLogs();
    $user = envUser([$editEnv]);

    $response = ForgeServer::actingAs($user)->tool(SetSiteEnvValues::class, [
        'server_id' => 1, 'site_id' => 12, 'set' => ['NEW_SECRET' => ENV_NEW_VALUE, 'DB_PASSWORD' => ENV_NEW_VALUE.'-2'],
    ])->assertOk()->assertSee('Added: NEW_SECRET. Changed: DB_PASSWORD.')
        ->assertDontSee(ENV_NEW_VALUE)
        ->assertDontSee('old-db-password-9911')
        ->assertDontSee('oldAppKeyValue1234567890abcdef');

    expect(envPut()['environment'])->toContain('NEW_SECRET='.ENV_NEW_VALUE);
    expect(envAuditDump())->not->toContain(ENV_NEW_VALUE)->not->toContain('old-db-password-9911');
    expect(implode("\n", $logs->getArrayCopy()))->not->toContain(ENV_NEW_VALUE);
});

it('a Forge 422 echoing the input is scrubbed of new and old values', function () use ($editEnv) {
    envFake(put: fn (HttpRequest $r) => Http::response([
        'message' => 'invalid',
        'errors' => ['environment' => ['The value '.ENV_NEW_VALUE.' near old-db-password-9911 is invalid.']],
    ], 422));
    $logs = envCaptureLogs();
    $user = envUser([$editEnv]);

    ForgeServer::actingAs($user)->tool(SetSiteEnvValues::class, [
        'server_id' => 1, 'site_id' => 12, 'set' => ['NEW_SECRET' => ENV_NEW_VALUE],
    ])->assertHasErrors(['Forge rejected the request', '[REDACTED:NEW_SECRET]', '[REDACTED:DB_PASSWORD]'])
        ->assertDontSee(ENV_NEW_VALUE)
        ->assertDontSee('old-db-password-9911');

    $entry = AuditEntry::query()->sole();
    expect($entry->outcome)->toBe('error');
    expect(envAuditDump())->not->toContain(ENV_NEW_VALUE)->not->toContain('old-db-password-9911');
    expect(implode("\n", $logs->getArrayCopy()))->not->toContain(ENV_NEW_VALUE);
});

it('an unexpected exception carrying the request body leaks no value to the log', function () use ($editEnv) {
    envFake(put: function (HttpRequest $r) {
        throw new RuntimeException('boom: '.json_encode($r->data()));
    });
    $logs = envCaptureLogs();
    $user = envUser([$editEnv]);

    ForgeServer::actingAs($user)->tool(SetSiteEnvValues::class, [
        'server_id' => 1, 'site_id' => 12, 'set' => ['GREETING' => ENV_NEW_VALUE],
    ])->assertHasErrors(['Unexpected error while running set_site_env_values'])
        ->assertDontSee(ENV_NEW_VALUE);

    $all = implode("\n", $logs->getArrayCopy());
    expect($all)->toContain('Forge MCP tool failed')->toContain('[REDACTED:GREETING]')
        ->not->toContain(ENV_NEW_VALUE)
        ->not->toContain('old-db-password-9911');
    expect(envAuditDump())->not->toContain(ENV_NEW_VALUE);
});

it('edit_env alone does not expose get_site_env', function () use ($editEnv) {
    $new = mcpNewToken(mcpOwner(), [$editEnv]);

    $names = mcpToolNames(mcpPost('tools/list', bearer: $new->plainTextToken)->assertOk());

    expect($names)->toContain('set_site_env_values')->not->toContain('get_site_env');
});

it('view_env exposes get_site_env but not set_site_env_values', function () use ($viewEnv) {
    $new = mcpNewToken(mcpOwner(), [$viewEnv]);

    $names = mcpToolNames(mcpPost('tools/list', bearer: $new->plainTextToken)->assertOk());

    expect($names)->toContain('get_site_env')->not->toContain('set_site_env_values');
});

it('invalid key rejected', function (array $args, string $error) use ($editEnv) {
    envFake();
    $user = envUser([$editEnv]);

    ForgeServer::actingAs($user)->tool(SetSiteEnvValues::class, ['server_id' => 1, 'site_id' => 12] + $args)
        ->assertHasErrors([$error]);

    envAssertNoPut();
})->with([
    'bad set key' => [['set' => ['1BAD' => 'x']], 'Invalid env key: 1BAD'],
    'dotted set key' => [['set' => ['A.B' => 'x']], 'Invalid env key: A.B'],
    'bad remove key' => [['remove' => ['BAD KEY']], 'Invalid env key: BAD KEY'],
    'set and remove' => [['set' => ['A' => 'x'], 'remove' => ['A']], 'A key cannot be both set and removed.'],
    'neither' => [[], 'Pass `set` and/or `remove`'],
    'both empty' => [['set' => [], 'remove' => []], 'Pass `set` and/or `remove`'],
    'array value' => [['set' => ['A' => ['x']]], 'The value for A must be a string.'],
    'number value' => [['set' => ['A' => 5]], 'The value for A must be a string.'],
    'nul value' => [['set' => ['A' => "x\0y"]], 'The value for A must not contain NUL characters.'],
    'set not object' => [['set' => 'A=1'], '`set` must be an object'],
    'remove not list' => [['remove' => 'A'], '`remove` must be a list'],
]);

it('rejected values are not echoed in errors or audit', function () use ($editEnv) {
    envFake();
    $user = envUser([$editEnv]);

    ForgeServer::actingAs($user)->tool(SetSiteEnvValues::class, ['server_id' => 1, 'site_id' => 12, 'set' => ['A' => ENV_NEW_VALUE."\0"]])
        ->assertHasErrors()->assertDontSee(ENV_NEW_VALUE);

    expect(envAuditDump())->not->toContain(ENV_NEW_VALUE);
});

it('set_site_env_values needs edit_env on the site', function () {
    envFake();
    $user = envUser([[Capability::EditEnv, ScopeType::Site, new Target('acme', 1, 13), true]]);

    ForgeServer::actingAs($user)->tool(SetSiteEnvValues::class, ['server_id' => 1, 'site_id' => 12, 'set' => ['NEW_SECRET' => ENV_NEW_VALUE]])
        ->assertHasErrors(['not permitted']);

    envAssertNoPut();
    expect(AuditEntry::query()->sole()->outcome)->toBe('denied');
    expect(envAuditDump())->not->toContain(ENV_NEW_VALUE);
});

it('secrets cache forgotten after edit', function () use ($editEnv) {
    envFake();
    $user = envUser([$editEnv]);
    Cache::put('forge-mcp:secrets:acme:12', Crypt::encryptString(json_encode(['STALE_SECRET' => 'stale-cached-secret-value'])), 600);
    ForgeServer::actingAs($user)->tool(SetSiteEnvValues::class, ['server_id' => 1, 'site_id' => 12, 'remove' => ['MAIL_FROM']])
        ->assertOk();

    $cached = Cache::get('forge-mcp:secrets:acme:12');
    expect($cached === null || ! str_contains(Crypt::decryptString($cached), 'stale-cached-secret-value'))->toBeTrue();
});

it('makes no write when nothing changes', function () use ($editEnv) {
    envFake();
    $user = envUser([$editEnv]);

    ForgeServer::actingAs($user)->tool(SetSiteEnvValues::class, ['server_id' => 1, 'site_id' => 12, 'set' => ['APP_NAME' => 'Alpha'], 'remove' => ['NOT_THERE']])
        ->assertOk()->assertSee('No changes to .env for alpha.test.');

    envAssertNoPut();
});

it('does not send the cache or queues flags', function () use ($editEnv) {
    envFake();
    $user = envUser([$editEnv]);

    ForgeServer::actingAs($user)->tool(SetSiteEnvValues::class, ['server_id' => 1, 'site_id' => 12, 'set' => ['X' => 'y']])->assertOk();

    expect(array_keys(envPut()))->toBe(['environment']);
});
