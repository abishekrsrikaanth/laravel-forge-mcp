<?php

use App\Forge\ForgeClient;
use App\Forge\WorkerSiteResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function resolverSites(array $roots): ForgeClient
{
    ForgeClient::flushCache();
    $records = [];
    foreach ($roots as $id => $root) {
        $records[] = ['id' => (string) $id, 'type' => 'sites', 'attributes' => ['root_directory' => $root]];
    }
    Http::fake(['forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(['data' => $records])]);

    return new ForgeClient('tkn');
}

it('uses an explicit site_id', function () {
    $client = resolverSites([1 => '/home/forge/a.com']);

    expect(WorkerSiteResolver::siteIdFor($client, 'acme', 1, ['site_id' => 9, 'directory' => '/home/forge/a.com']))->toBe(9);
});

it('matches a worker directory inside a site root', function () {
    $client = resolverSites([1 => '/home/forge/a.com/', 2 => '/home/forge/b.com']);

    expect(WorkerSiteResolver::siteIdFor($client, 'acme', 1, ['directory' => '/home/forge/a.com/current']))->toBe(1)
        ->and(WorkerSiteResolver::siteIdFor($client, 'acme', 1, ['directory' => '/home/forge/b.com/']))->toBe(2);
});

it('picks the longest matching root for nested sites', function () {
    $client = resolverSites([1 => '/home/forge/a.com', 2 => '/home/forge/a.com/sub']);

    expect(WorkerSiteResolver::siteIdFor($client, 'acme', 1, ['directory' => '/home/forge/a.com/sub/current']))->toBe(2)
        ->and(WorkerSiteResolver::siteIdFor($client, 'acme', 1, ['directory' => '/home/forge/a.com/current']))->toBe(1);
});

it('requires a path segment boundary', function () {
    $client = resolverSites([1 => '/home/forge/a.com']);

    expect(WorkerSiteResolver::siteIdFor($client, 'acme', 1, ['directory' => '/home/forge/a.com.bak']))->toBeNull();
});

it('returns null with no match, no directory, or a filesystem-root site', function () {
    $client = resolverSites([1 => '/home/forge/a.com', 2 => '/']);

    expect(WorkerSiteResolver::siteIdFor($client, 'acme', 1, ['directory' => '/opt/other']))->toBeNull()
        ->and(WorkerSiteResolver::siteIdFor($client, 'acme', 1, []))->toBeNull();
});
