<?php

use App\Permissions\Capability;
use App\Permissions\Target;
use App\Permissions\Tier;
use Tests\TestCase;

uses(TestCase::class);

it('defines exactly the twelve capabilities', function () {
    expect(array_map(fn (Capability $c) => $c->value, Capability::cases()))->toBe([
        'view', 'view_logs', 'deploy', 'restart_services', 'manage_workers', 'view_env',
        'edit_env', 'run_commands', 'configure_workers', 'configure_deployments', 'reboot_server', 'maintenance_mode',
    ]);
});

it('assigns tiers', function (Capability $capability, Tier $tier) {
    expect($capability->tier())->toBe($tier);
})->with([
    [Capability::View, Tier::Read],
    [Capability::ViewLogs, Tier::Read],
    [Capability::Deploy, Tier::Action],
    [Capability::RestartServices, Tier::Action],
    [Capability::ManageWorkers, Tier::Action],
    [Capability::MaintenanceMode, Tier::Action],
    [Capability::ViewEnv, Tier::Risky],
    [Capability::EditEnv, Tier::Risky],
    [Capability::RunCommands, Tier::Risky],
    [Capability::ConfigureWorkers, Tier::Risky],
    [Capability::ConfigureDeployments, Tier::Risky],
    [Capability::RebootServer, Tier::Risky],
]);

it('maps capabilities to Forge token scopes', function (Capability $capability, array $scopes) {
    expect($capability->forgeScopes())->toBe($scopes);
})->with([
    [Capability::View, ['server:view']],
    [Capability::ViewLogs, ['server:manage-logs', 'site:manage-deploys', 'server:create-daemons']],
    [Capability::Deploy, ['site:manage-deploys']],
    [Capability::RestartServices, ['server:manage-services']],
    [Capability::ManageWorkers, ['server:create-daemons']],
    [Capability::ViewEnv, ['server:view']],
    [Capability::EditEnv, ['site:manage-environment']],
    [Capability::RunCommands, ['site:manage-commands']],
    [Capability::ConfigureWorkers, ['server:create-daemons', 'server:delete-daemons']],
    [Capability::ConfigureDeployments, ['site:manage-deploys']],
    [Capability::RebootServer, ['server:manage-services']],
    [Capability::MaintenanceMode, ['site:manage-commands']],
]);

it('only allows view and view_logs by default', function () {
    $defaults = array_values(array_filter(Capability::cases(), fn (Capability $c) => $c->defaultAllowed()));

    expect($defaults)->toBe([Capability::View, Capability::ViewLogs]);
});

it('excludes restart_services and reboot_server from site scope', function () {
    $noSite = array_values(array_filter(Capability::cases(), fn (Capability $c) => ! $c->allowsSiteScope()));

    expect($noSite)->toBe([Capability::RestartServices, Capability::RebootServer]);
});

it('has a label and description for every capability', function () {
    foreach (Capability::cases() as $capability) {
        expect($capability->label())->not->toBe('')
            ->and($capability->description())->not->toBe('');
    }
});

it('groups Forge scopes with the capabilities that need them', function () {
    $scopes = Capability::requiredForgeScopes();

    expect(array_keys($scopes))->toBe([
        'server:view', 'server:manage-logs', 'site:manage-deploys', 'server:create-daemons',
        'server:manage-services', 'site:manage-environment', 'site:manage-commands', 'server:delete-daemons',
    ])
        ->and($scopes['server:view'])->toBe([Capability::View, Capability::ViewEnv])
        ->and($scopes['server:delete-daemons'])->toBe([Capability::ConfigureWorkers]);
});

it('describes targets', function (Target $target, string $description) {
    expect($target->describe())->toBe($description);
})->with([
    [new Target('acme', 3, 12), 'site 12 on server 3 (acme)'],
    [new Target('acme', null, 12), 'site 12 (acme)'],
    [new Target('acme', 3), 'server 3 (acme)'],
    [new Target('acme'), 'organization acme'],
    [new Target, 'all organizations'],
]);
