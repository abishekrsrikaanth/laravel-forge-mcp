<?php

use App\Forge\ForgeClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

const SRV = 'https://forge.laravel.com/api/orgs/acme/servers/1';
const SITE = SRV.'/sites/9';

function forge(): ForgeClient
{
    return new ForgeClient('tkn');
}

function res(string $type, int|string $id, array $attributes = [], array $extra = []): array
{
    return ['id' => (string) $id, 'type' => $type, 'attributes' => $attributes] + $extra;
}

function doc(string $type, int|string $id, array $attributes = []): array
{
    return ['data' => res($type, $id, $attributes)];
}

function fakeOne(string $url, mixed $response = null): void
{
    Http::fake([$url.'*' => $response ?? Http::response('', 204)]);
}

function assertSentTo(string $method, string $url, ?array $body = null): void
{
    Http::assertSent(function ($r) use ($method, $url, $body) {
        $matches = $r->method() === $method && strtok(urldecode($r->url()), '?') === $url;

        if ($body !== null) {
            $actual = $r->data();
            ksort($actual);
            ksort($body);
        }

        return $matches && ($body === null || $actual === $body);
    });
}

beforeEach(fn () => ForgeClient::flushCache());

it('stops paginating when a cursor repeats', function () {
    Http::fake(['*' => Http::response(['data' => [res('servers', 1)], 'meta' => ['next_cursor' => 'same']])]);

    $servers = forge()->servers('acme');

    Http::assertSentCount(2);
    expect($servers)->toHaveCount(2);
});

it('url-encodes the organization slug in paths', function () {
    Http::fake(['*' => Http::response(doc('servers', 1))]);

    forge()->server('my org/x', 1);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/orgs/my%20org%2Fx/servers/1'));
});

it('send returns the flattened record for a json body', function () {
    Http::fake(['*' => Http::response(doc('things', 5, ['name' => 'n']), 201)]);

    $client = new class('tkn') extends ForgeClient
    {
        public function call(): ?array
        {
            return $this->send('POST', '/x', ['a' => 1], 'do x');
        }
    };

    expect($client->call())->toBe(['id' => 5, 'name' => 'n']);
    assertSentTo('POST', 'https://forge.laravel.com/api/x', ['a' => 1]);
});

it('send returns null for an empty response', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $client = new class('tkn') extends ForgeClient
    {
        public function call(): ?array
        {
            return $this->send('DELETE', '/x', [], 'do x');
        }
    };

    expect($client->call())->toBeNull();
});

it('deploys a site', function () {
    fakeOne(SITE.'/deployments', Http::response(doc('deployments', 3, ['status' => 'queued']), 201));

    expect(forge()->deploy('acme', 1, 9))->toMatchArray(['id' => 3, 'status' => 'queued']);
    assertSentTo('POST', SITE.'/deployments');
});

it('reads deployment status', function () {
    fakeOne(SITE.'/deployments/status', Http::response(doc('deploymentStatuses', 1, ['status' => 'deploying', 'started_at' => '2025-01-01T00:00:00Z'])));

    expect(forge()->deploymentStatus('acme', 1, 9))->toBe(['status' => 'deploying', 'started_at' => '2025-01-01T00:00:00Z']);
});

it('reads null deployment status when idle', function () {
    fakeOne(SITE.'/deployments/status', Http::response(['data' => null]));

    expect(forge()->deploymentStatus('acme', 1, 9))->toBe(['status' => null, 'started_at' => null]);
});

it('reads one deployment by id', function () {
    fakeOne(SITE.'/deployments/3', Http::response(doc('deployments', 3, ['status' => 'failed-build'])));

    expect(forge()->deployment('acme', 1, 9, 3))->toMatchArray(['id' => 3, 'status' => 'failed-build']);
});

it('lists deployments with a single page', function () {
    fakeOne(SITE.'/deployments', Http::response(['data' => [res('deployments', 3), res('deployments', 2)]]));

    expect(array_column(forge()->deployments('acme', 1, 9, 2), 'id'))->toBe([3, 2]);
    Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains(urldecode($r->url()), 'page[size]=2'));
});

it('reads the deployment log', function () {
    fakeOne(SITE.'/deployments/3/log', Http::response(doc('deploymentLogs', 3, ['output' => 'built ok'])));

    expect(forge()->deploymentLog('acme', 1, 9, 3))->toBe('built ok');
});

it('reads site logs', function (string $type) {
    fakeOne(SITE.'/logs/'.$type, Http::response(doc('logs', 1, ['content' => 'line'])));

    expect(forge()->siteLog('acme', 1, 9, $type))->toBe('line');
    assertSentTo('GET', SITE.'/logs/'.$type);
})->with(['application', 'nginx-access', 'nginx-error']);

it('rejects unknown site log types', function () {
    Http::fake();

    expect(fn () => forge()->siteLog('acme', 1, 9, '../etc/passwd'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('reads and updates the environment', function () {
    fakeOne(SITE.'/environment', Http::response(doc('environments', 1, ['content' => 'APP_ENV=prod'])));

    expect(forge()->environment('acme', 1, 9))->toBe('APP_ENV=prod');

    forge()->updateEnvironment('acme', 1, 9, 'APP_ENV=local');
    assertSentTo('PUT', SITE.'/environment', ['environment' => 'APP_ENV=local']);
});

it('reboots nginx', function () {
    fakeOne(SRV.'/services/nginx/actions');

    forge()->nginxAction('acme', 1, 'reboot');
    assertSentTo('POST', SRV.'/services/nginx/actions', ['action' => 'reboot']);
});

it('runs php actions', function () {
    fakeOne(SRV.'/services/php/actions');

    forge()->phpAction('acme', 1, 'reload', 'php84');
    assertSentTo('POST', SRV.'/services/php/actions', ['action' => 'reload', 'version' => 'php84']);
});

it('manages background processes', function () {
    Http::fake([
        SRV.'/background-processes/4/log*' => Http::response(doc('backgroundProcessLogs', 4, ['content' => 'log'])),
        SRV.'/background-processes/4/actions' => Http::response('', 202),
        SRV.'/background-processes/4' => Http::response(doc('backgroundProcesses', 4, ['name' => 'w'])),
        SRV.'/background-processes*' => Http::response(['data' => [res('backgroundProcesses', 4, ['name' => 'w'])]]),
    ]);
    $c = forge();

    expect($c->backgroundProcesses('acme', 1)[0])->toMatchArray(['id' => 4, 'name' => 'w'])
        ->and($c->backgroundProcess('acme', 1, 4))->toMatchArray(['id' => 4])
        ->and($c->backgroundProcessLog('acme', 1, 4))->toBe('log');

    $c->backgroundProcessAction('acme', 1, 4, 'restart');
    assertSentTo('POST', SRV.'/background-processes/4/actions', ['action' => 'restart']);

    $c->updateBackgroundProcess('acme', 1, 4, 'new', 'cfg');
    assertSentTo('PUT', SRV.'/background-processes/4', ['name' => 'new', 'config' => 'cfg']);

    $c->updateBackgroundProcess('acme', 1, 4, 'new', null);
    assertSentTo('PUT', SRV.'/background-processes/4', ['name' => 'new']);

    $c->deleteBackgroundProcess('acme', 1, 4);
    assertSentTo('DELETE', SRV.'/background-processes/4');
});

it('creates a background process', function () {
    fakeOne(SRV.'/background-processes', Http::response(doc('backgroundProcesses', 8, ['name' => 'q']), 201));
    $attrs = ['name' => 'q', 'command' => 'php artisan queue:work', 'user' => 'forge', 'processes' => 2, 'site_id' => 9, 'directory' => '/home/forge/x', 'bogus' => 'drop'];

    expect(forge()->createBackgroundProcess('acme', 1, $attrs))->toMatchArray(['id' => 8]);
    assertSentTo('POST', SRV.'/background-processes', [
        'name' => 'q', 'command' => 'php artisan queue:work', 'user' => 'forge', 'processes' => 2, 'site_id' => 9, 'directory' => '/home/forge/x',
    ]);
});

it('rejects unknown worker, php and nginx actions', function () {
    Http::fake();
    $c = forge();

    expect(fn () => $c->backgroundProcessAction('acme', 1, 4, 'empty-log'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $c->phpAction('acme', 1, 'stop', 'php84'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $c->nginxAction('acme', 1, 'stop'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $c->serverAction('acme', 1, 'power-cycle'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $c->serviceAction('acme', 1, 'docker', 'reboot'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $c->serviceAction('acme', 1, 'redis', 'stop'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $c->integration('acme', 1, 9, 'pulse'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $c->enableMaintenance('acme', 1, 9, null, 500))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('runs and reads commands', function () {
    Http::fake([
        SITE.'/commands/6/output*' => Http::response(doc('commandOutputs', 6, ['output' => 'hi'])),
        SITE.'/commands/6' => Http::response(doc('commands', 6, ['status' => 'finished', 'exit_code' => 0, 'command' => 'ls'])),
        SITE.'/commands*' => fn ($r) => $r->method() === 'POST'
            ? Http::response('', 202)
            : Http::response(['data' => [res('commands', 6)]]),
    ]);
    $c = forge();

    $c->runCommand('acme', 1, 9, 'ls');
    assertSentTo('POST', SITE.'/commands', ['command' => 'ls']);

    expect(array_column($c->commands('acme', 1, 9, 3), 'id'))->toBe([6])
        ->and($c->command('acme', 1, 9, 6))->toMatchArray(['status' => 'finished', 'exit_code' => 0])
        ->and($c->commandOutput('acme', 1, 9, 6))->toBe('hi');
    Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains(urldecode($r->url()), 'sort=-created_at') && str_contains(urldecode($r->url()), 'page[size]=3'));
});

it('reads and updates the deployment script', function () {
    fakeOne(SITE.'/deployments/script', Http::response(doc('deploymentScripts', 1, ['content' => 'git pull', 'auto_source' => true])));

    expect(forge()->deploymentScript('acme', 1, 9))->toBe('git pull');

    forge()->updateDeploymentScript('acme', 1, 9, 'php artisan migrate');
    assertSentTo('PUT', SITE.'/deployments/script', ['content' => 'php artisan migrate']);
});

it('resets deployment status', function () {
    fakeOne(SITE.'/deployments/status');

    forge()->resetDeploymentStatus('acme', 1, 9);
    assertSentTo('DELETE', SITE.'/deployments/status');
});

it('sets push to deploy via the dedicated endpoint', function () {
    Http::fake(['*' => Http::response('', 202)]);
    $c = forge();

    $c->setPushToDeploy('acme', 1, 9, true);
    assertSentTo('POST', SITE.'/deployments/push-to-deploy');

    $c->setPushToDeploy('acme', 1, 9, false);
    assertSentTo('DELETE', SITE.'/deployments/push-to-deploy');

    Http::assertSentCount(2);
    Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
});

it('reboots the server and other services', function () {
    Http::fake(['*' => Http::response('', 202)]);
    $c = forge();

    $c->serverAction('acme', 1, 'reboot');
    assertSentTo('POST', SRV.'/actions', ['action' => 'reboot']);

    $c->serviceAction('acme', 1, 'postgres', 'reboot');
    assertSentTo('POST', SRV.'/services/postgres/actions', ['action' => 'reboot']);
});

it('reads integrations with included processes', function () {
    fakeOne(SITE.'/integrations/horizon', Http::response([
        'data' => res('horizons', 1, ['enabled' => true]),
        'included' => [res('backgroundProcesses', 4, ['name' => 'horizon'])],
    ]));

    $integration = forge()->integration('acme', 1, 9, 'horizon');

    expect($integration)->toMatchArray(['id' => 1, 'enabled' => true])
        ->and($integration['processes'][0])->toMatchArray(['id' => 4, 'name' => 'horizon']);
});

it('returns empty processes when nothing is included', function () {
    fakeOne(SITE.'/integrations/octane', Http::response(doc('octanes', 1, ['enabled' => false])));

    expect(forge()->integration('acme', 1, 9, 'octane')['processes'])->toBe([]);
});

it('toggles maintenance mode', function () {
    fakeOne(SITE.'/integrations/laravel-maintenance');
    $c = forge();

    $c->enableMaintenance('acme', 1, 9, 'sekret', 503, 'https://example.com');
    assertSentTo('POST', SITE.'/integrations/laravel-maintenance', ['secret' => 'sekret', 'status' => 503, 'redirect' => 'https://example.com']);

    $c->enableMaintenance('acme', 1, 9, null);
    assertSentTo('POST', SITE.'/integrations/laravel-maintenance', ['status' => 503]);

    $c->disableMaintenance('acme', 1, 9);
    assertSentTo('DELETE', SITE.'/integrations/laravel-maintenance');
});

it('lists scheduled jobs and their output', function () {
    Http::fake([
        SRV.'/scheduled-jobs/2/output*' => Http::response(doc('scheduledJobOutputs', 2, ['output' => 'srv out'])),
        SITE.'/scheduled-jobs/2/output*' => Http::response(doc('scheduledJobOutputs', 2, ['output' => 'site out'])),
        SITE.'/scheduled-jobs*' => Http::response(['data' => [res('scheduledJobs', 20)]]),
        SRV.'/scheduled-jobs*' => Http::response(['data' => [res('scheduledJobs', 2)]]),
    ]);
    $c = forge();

    expect(array_column($c->scheduledJobs('acme', 1), 'id'))->toBe([2])
        ->and(array_column($c->scheduledJobs('acme', 1, 9), 'id'))->toBe([20])
        ->and($c->scheduledJobOutput('acme', 1, 2))->toBe('srv out')
        ->and($c->scheduledJobOutput('acme', 1, 2, 9))->toBe('site out');
});

it('lists events and event output', function () {
    Http::fake([
        SRV.'/events/5/output*' => Http::response(doc('eventOutputs', 5, ['output' => 'evt'])),
        SRV.'/events*' => Http::response(['data' => [res('events', 5)]]),
    ]);
    $c = forge();

    expect(array_column($c->events('acme', 1, 7), 'id'))->toBe([5])
        ->and($c->eventOutput('acme', 1, 5))->toBe('evt');
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), '/events?') && str_contains(urldecode($r->url()), 'page[size]=7'));
});

it('environmentOrNull distinguishes a missing environment from an empty one', function (array $attributes, ?string $expected) {
    fakeOne(SITE.'/environment', Http::response(doc('environments', 1, $attributes)));

    expect(forge()->environmentOrNull('acme', 1, 9))->toBe($expected);
})->with([
    'content' => [['content' => 'A=1'], 'A=1'],
    'empty' => [['content' => ''], ''],
    'null' => [['content' => null], null],
    'absent' => [[], null],
    'not a string' => [['content' => ['x']], null],
]);
