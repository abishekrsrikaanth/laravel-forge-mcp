<?php

use App\Forge\ForgeClient;
use App\Forge\ForgeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function forgeFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/forge/'.$name.'.json'), true);
}

beforeEach(fn () => ForgeClient::flushCache());

it('sends bearer token and json accept header', function () {
    Http::fake(['forge.laravel.com/api/orgs*' => Http::response(forgeFixture('organizations'))]);

    $orgs = (new ForgeClient('tkn'))->organizations();

    expect($orgs[0])->toMatchArray(['id' => 7, 'slug' => 'acme', 'name' => 'Acme']);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tkn')
        && $r->hasHeader('Accept', 'application/json')
        && str_starts_with($r->url(), 'https://forge.laravel.com/api/orgs'));
});

it('flattens json api records', function () {
    Http::fake(['*' => Http::response(forgeFixture('servers'))]);

    $servers = (new ForgeClient('tkn'))->servers('acme');

    expect($servers[0]['id'])->toBe(1)
        ->and($servers[0]['name'])->toBe('web-1')
        ->and($servers[0]['organization_id'])->toBe(7);
});

it('flattens a single record with relationship ids', function () {
    Http::fake(['*' => Http::response(forgeFixture('site'))]);

    $site = (new ForgeClient('tkn'))->site('acme', 42);

    expect($site)->toMatchArray(['id' => 42, 'name' => 'example.com', 'server_id' => 1]);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/orgs/acme/sites/42'));
});

it('follows cursor pagination', function () {
    $page = fn (int $id, ?string $next) => ['data' => [['id' => (string) $id, 'type' => 'servers', 'attributes' => ['name' => "s$id"]]], 'meta' => ['next_cursor' => $next]];
    Http::fake(function ($request) use ($page) {
        return str_contains(urldecode($request->url()), 'page[cursor]=abc')
            ? Http::response($page(2, null))
            : Http::response($page(1, 'abc'));
    });

    $servers = (new ForgeClient('tkn'))->servers('acme');

    expect(array_column($servers, 'id'))->toBe([1, 2]);
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'page[cursor]=abc'));
});

it('stops after 20 pages', function () {
    $n = 0;
    Http::fake(function () use (&$n) {
        return Http::response(['data' => [['id' => '1', 'type' => 'servers', 'attributes' => []]], 'meta' => ['next_cursor' => 'c'.++$n]]);
    });

    (new ForgeClient('tkn'))->servers('acme');

    Http::assertSentCount(20);
});

it('caches list endpoints for list_cache_ttl', function () {
    Http::fake(['*' => Http::response(forgeFixture('servers'))]);
    $client = new ForgeClient('tkn');

    $client->servers('acme');
    $client->servers('acme');
    Http::assertSentCount(1);

    ForgeClient::flushCache();
    $client->servers('acme');
    Http::assertSentCount(2);
});

it('maps errors to agent messages', function (int $status, array $body, array $headers, string $expected) {
    Http::fake(['*' => Http::response($body, $status, $headers)]);

    try {
        (new ForgeClient('tkn'))->server('acme', 1);
        $this->fail('Expected ForgeException');
    } catch (ForgeException $e) {
        expect($e->status)->toBe($status)
            ->and($e->agentMessage())->toContain($expected)
            ->and($e->getMessage())->toBe($e->agentMessage());
    }
})->with([
    '401' => [401, [], [], 'Forge rejected the API token (401). Reconnect in the Forge MCP dashboard.'],
    '403' => [403, [], [], 'Forge denied fetch server (403). The Forge API token may be missing a required scope.'],
    '404' => [404, [], [], 'Forge could not find the requested resource (404) while trying to fetch server.'],
    '422 errors' => [422, ['message' => 'The given data was invalid.', 'errors' => ['name' => ['The name field is required.']]], [], 'The name field is required.'],
    '422 message' => [422, ['message' => 'Bad thing'], [], 'Bad thing'],
    '429 retry-after' => [429, [], ['Retry-After' => '17'], 'Forge rate limit reached'],
    '429 seconds' => [429, [], ['Retry-After' => '17'], '17'],
    '429 reset' => [429, [], ['X-RateLimit-Reset' => (string) (time() + 30)], 'Forge rate limit reached'],
    '500' => [500, [], [], 'Forge returned an unexpected error (500) while trying to fetch server.'],
]);

it('never includes the token in exception messages', function () {
    Http::fake(['*' => Http::response(['message' => 'bad token SECRET-TKN-123'], 422)]);

    try {
        (new ForgeClient('SECRET-TKN-123'))->server('acme', 1);
        $this->fail('Expected ForgeException');
    } catch (ForgeException $e) {
        expect($e->getMessage())->not->toContain('SECRET-TKN-123');
    }
});

it('wraps connection failures in a ForgeException', function () {
    Http::fake(fn () => throw new ConnectionException('boom'));

    (new ForgeClient('tkn'))->me();
})->throws(ForgeException::class, 'Could not reach Forge');
