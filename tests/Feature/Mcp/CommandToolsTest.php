<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\GetCommandResult;
use App\Mcp\Tools\RunSiteCommand;
use App\Models\AuditEntry;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function cmdRecord(array $attrs = []): array
{
    return ['id' => '88', 'type' => 'commands', 'attributes' => $attrs + [
        'command' => 'php artisan migrate --force',
        'status' => 'finished',
        'exit_code' => 0,
        'created_at' => '2026-11-03T10:00:01Z',
        'error_output' => '',
    ]];
}

/**
 * @param  list<string>  $statuses  successive statuses of command 88
 * @param  list<array<string, mixed>>|null  $listed  override the commands listing
 */
function cmdFakes(array $statuses = ['finished'], string $output = "migrating\ndone\n", ?array $listed = null, bool $recordFails = false, array $extra = []): void
{
    ForgeClient::flushCache();
    Cache::flush();
    Carbon::setTestNow('2026-11-03 10:00:00');

    $seq = Http::sequence();
    foreach ($statuses as $status) {
        $seq->push(['data' => cmdRecord(['status' => $status] + $extra)]);
    }
    $seq->whenEmpty(Http::response(['data' => cmdRecord(['status' => end($statuses)] + $extra)]));

    Http::fake([
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/commands/88/output' => Http::response(['data' => ['attributes' => ['output' => $output]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/commands/88' => $recordFails ? Http::response(['message' => 'boom'], 500) : $seq,
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/commands*' => fn ($request) => $request->method() === 'POST'
            ? Http::response('', 202)
            : Http::response(['data' => $listed ?? [
                cmdRecord(['command' => 'ls', 'created_at' => '2026-11-03T09:00:00Z']) + ['id' => '87'],
                cmdRecord(),
            ]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/environment' => Http::response(['data' => ['attributes' => ['content' => "APP_KEY=base64:supersecretvalue123456\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 12, 'name' => 'alpha.test', 'server_id' => 1],
        ])),
        'forge.laravel.com/api/orgs/acme/servers*' => Http::response(mcpForgeCollection('servers', [['id' => 1, 'name' => 'web-1']])),
    ]);
}

function cmdUser(): object
{
    $user = mcpOwner(['acme']);
    $token = mcpToken($user, [
        [Capability::RunCommands, ScopeType::Global, new Target, true],
        [Capability::ViewLogs, ScopeType::Global, new Target, true],
    ]);
    mcpActAs($user, $token);

    return $user;
}

afterEach(fn () => Carbon::setTestNow());

$args = ['server_id' => 1, 'site_id' => 12];
$run = $args + ['command' => 'php artisan migrate --force'];

it('run_site_command finishes and returns exit code and output', function () use ($run) {
    Sleep::fake();
    cmdFakes(['running', 'finished']);

    ForgeServer::actingAs(cmdUser())->tool(RunSiteCommand::class, $run)
        ->assertOk()
        ->assertSee(['exit 0, status finished', 'migrating', 'done']);

    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['command'] === 'php artisan migrate --force');
});

it('run_site_command includes error output when present', function () use ($run) {
    Sleep::fake();
    cmdFakes(['failed'], extra: ['exit_code' => 1, 'error_output' => 'Class not found']);

    ForgeServer::actingAs(cmdUser())->tool(RunSiteCommand::class, $run)
        ->assertOk()
        ->assertSee(['exit 1, status failed', 'Class not found']);
});

it('run_site_command times out with a follow-up hint', function () use ($run) {
    Sleep::fake();
    cmdFakes(['running']);

    ForgeServer::actingAs(cmdUser())->tool(RunSiteCommand::class, $run)
        ->assertOk()
        ->assertSee('Command 88 is still running. Call get_command_result with command_id 88.');

    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/commands/88/output'));
});

it('run_site_command reports when the command id cannot be determined', function () use ($run) {
    Sleep::fake();
    cmdFakes(listed: [
        cmdRecord(['command' => 'something else']),
        cmdRecord(['created_at' => '2026-11-03T09:00:00Z']),
    ]);

    ForgeServer::actingAs(cmdUser())->tool(RunSiteCommand::class, $run)
        ->assertOk()
        ->assertSee("Command queued, but its id could not be determined. Check the site's commands in Forge.");
});

it('run_site_command keeps the command id when checking fails', function () use ($run) {
    Sleep::fake();
    cmdFakes(recordFails: true);

    ForgeServer::actingAs(cmdUser())->tool(RunSiteCommand::class, $run)
        ->assertOk()
        ->assertSee(['Command 88 was queued, but checking its result failed', 'Call get_command_result with command_id 88.']);
});

it('run_site_command masks site secrets in output', function () use ($run) {
    Sleep::fake();
    cmdFakes(output: "key=base64:supersecretvalue123456\nok\n");

    ForgeServer::actingAs(cmdUser())->tool(RunSiteCommand::class, $run)
        ->assertOk()->assertSee('ok')->assertDontSee('supersecretvalue123456');
});

it('run_site_command stores the command text in the audit arguments', function () use ($run) {
    Sleep::fake();
    cmdFakes();

    ForgeServer::actingAs(cmdUser())->tool(RunSiteCommand::class, $run)->assertOk();

    $entry = AuditEntry::query()->sole();
    expect($entry->tool)->toBe('run_site_command')
        ->and($entry->arguments['command'])->toBe('php artisan migrate --force');
});

it('run_site_command validates the command text', function (mixed $command) use ($args) {
    cmdFakes();

    ForgeServer::actingAs(cmdUser())->tool(RunSiteCommand::class, $args + ['command' => $command])->assertHasErrors();

    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
})->with(['empty' => '', 'too long' => fn () => str_repeat('a', 2001), 'nul byte' => "ls\0rm"]);

it('run_site_command is denied on a site without run_commands and hidden for a fresh token', function () use ($run) {
    cmdFakes();
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user, [[Capability::RunCommands, ScopeType::Site, new Target('acme', 1, 13), true]]));

    ForgeServer::actingAs($user)->tool(RunSiteCommand::class, $run)->assertHasErrors(['not permitted']);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');

    $new = mcpNewToken(mcpOwner());
    expect(mcpToolNames(mcpPost('tools/list', bearer: $new->plainTextToken)->assertOk()))
        ->not->toContain('run_site_command')->toContain('get_command_result');
});

it('get_command_result returns the formatted result and tails output', function () use ($args) {
    $out = implode("\n", array_map(fn ($i) => "line {$i}", range(1, 30)));
    cmdFakes(output: $out);

    ForgeServer::actingAs(cmdUser())->tool(GetCommandResult::class, $args + ['command_id' => 88, 'lines' => 3])
        ->assertOk()
        ->assertSee(['exit 0, status finished', 'line 30', 'line 28'])
        ->assertDontSee('line 27');
});

it('get_command_result reports a command that is still running', function () use ($args) {
    cmdFakes(['running']);

    ForgeServer::actingAs(cmdUser())->tool(GetCommandResult::class, $args + ['command_id' => 88])
        ->assertOk()
        ->assertSee('Command 88 is still running. Call get_command_result with command_id 88.');
});

it('get_command_result needs view_logs only', function () use ($args) {
    cmdFakes();
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user, [[Capability::ViewLogs, ScopeType::Global, new Target, true]]));

    ForgeServer::actingAs($user)->tool(GetCommandResult::class, $args + ['command_id' => 88])->assertOk();
});
