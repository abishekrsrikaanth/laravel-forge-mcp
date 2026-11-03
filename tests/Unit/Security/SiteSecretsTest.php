<?php

use App\Forge\ForgeClient;
use App\Security\SecretScrubber;
use App\Security\SiteSecrets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

const SECRETS_SERVER = 'https://forge.laravel.com/api/orgs/acme/servers/1';

function envDoc(string $content): array
{
    return ['data' => ['id' => '1', 'type' => 'environments', 'attributes' => ['content' => $content]]];
}

function sitesDoc(array $ids): array
{
    return ['data' => array_map(fn (int $id) => ['id' => (string) $id, 'type' => 'sites', 'attributes' => ['name' => "s{$id}.test"]], $ids)];
}

beforeEach(function () {
    ForgeClient::flushCache();
    Cache::flush();
});

it('caches the env encrypted and does not refetch', function () {
    Http::fake([SECRETS_SERVER.'/sites/9/environment*' => Http::response(envDoc("DB_PASSWORD=s3cr3tPassw0rd\nAPP_ENV=production\n"))]);
    $client = new ForgeClient('tkn');

    $first = SiteSecrets::forSite($client, 'acme', 1, 9);
    $second = SiteSecrets::forSite($client, 'acme', 1, 9);

    Http::assertSentCount(1);
    $raw = Cache::get('forge-mcp:secrets:acme:9');
    expect($raw)->toBeString()->not->toContain('s3cr3tPassw0rd')->not->toContain('DB_PASSWORD')
        ->and($first->complete)->toBeTrue()
        ->and($second)->toEqual($first)
        ->and((new SecretScrubber)->scrub('pw s3cr3tPassw0rd', $second))->toBe('pw [REDACTED:DB_PASSWORD]');
});

it('refetches when the cached payload cannot be decrypted', function () {
    Http::fake([SECRETS_SERVER.'/sites/9/environment*' => Http::response(envDoc("DB_PASSWORD=s3cr3tPassw0rd\n"))]);
    Cache::put('forge-mcp:secrets:acme:9', 'garbage', 600);

    $set = SiteSecrets::forSite(new ForgeClient('tkn'), 'acme', 1, 9);

    Http::assertSentCount(1);
    expect((new SecretScrubber)->scrub('s3cr3tPassw0rd', $set))->toBe('[REDACTED:DB_PASSWORD]');
});

it('forget drops the cache', function () {
    Http::fake([SECRETS_SERVER.'/sites/9/environment*' => Http::response(envDoc("DB_PASSWORD=s3cr3tPassw0rd\n"))]);
    $client = new ForgeClient('tkn');

    SiteSecrets::forSite($client, 'acme', 1, 9);
    SiteSecrets::forget('acme', 9);

    expect(Cache::has('forge-mcp:secrets:acme:9'))->toBeFalse();
    SiteSecrets::forSite($client, 'acme', 1, 9);
    Http::assertSentCount(2);
});

it('forServer unions all sites', function () {
    Http::fake([
        SECRETS_SERVER.'/sites/9/environment*' => Http::response(envDoc("DB_PASSWORD=firstSitePassword\n")),
        SECRETS_SERVER.'/sites/10/environment*' => Http::response(envDoc("DB_PASSWORD=secondSitePassword\nAPI_TOKEN=secondSiteToken1\n")),
        SECRETS_SERVER.'/sites*' => Http::response(sitesDoc([9, 10])),
    ]);

    $set = SiteSecrets::forServer(new ForgeClient('tkn'), 'acme', 1);

    expect($set->complete)->toBeTrue()
        ->and((new SecretScrubber)->scrub('firstSitePassword secondSitePassword secondSiteToken1', $set))
        ->toBe('[REDACTED:DB_PASSWORD] [REDACTED:DB_PASSWORD] [REDACTED:API_TOKEN]');
});

it('forServer reuses per-site cache entries', function () {
    Http::fake([
        SECRETS_SERVER.'/sites/9/environment*' => Http::response(envDoc("DB_PASSWORD=firstSitePassword\n")),
        SECRETS_SERVER.'/sites/10/environment*' => Http::response(envDoc("DB_PASSWORD=secondSitePassword\n")),
        SECRETS_SERVER.'/sites*' => Http::response(sitesDoc([9, 10])),
    ]);
    $client = new ForgeClient('tkn');

    SiteSecrets::forSite($client, 'acme', 1, 9);
    SiteSecrets::forServer($client, 'acme', 1);
    SiteSecrets::forServer($client, 'acme', 1);

    // 1 env (site 9) + 1 sites list + 1 env (site 10); the second forServer is fully cached.
    Http::assertSentCount(3);
});

it('forServer caps uncached env fetches and reports the set as incomplete', function () {
    $ids = range(1, SiteSecrets::MAX_FETCHES_PER_CALL + 2);
    Http::fake([
        SECRETS_SERVER.'/sites/*/environment*' => Http::response(envDoc("DB_PASSWORD=somePassword123\n")),
        SECRETS_SERVER.'/sites*' => Http::response(sitesDoc($ids)),
    ]);

    $set = SiteSecrets::forServer(new ForgeClient('tkn'), 'acme', 1);

    Http::assertSentCount(1 + SiteSecrets::MAX_FETCHES_PER_CALL);
    expect($set->complete)->toBeFalse()
        ->and($set->warnings)->toBe(['Secret scrubbing limited: .env not yet read for 2 of '.count($ids).' sites on server 1; retry shortly.']);
});

it('returns an incomplete set with a warning when the env cannot be read', function () {
    Http::fake([SECRETS_SERVER.'/sites/9/environment*' => Http::response(['message' => 'nope'], 403)]);

    $set = SiteSecrets::forSite(new ForgeClient('tkn'), 'acme', 1, 9);

    expect($set->complete)->toBeFalse()
        ->and($set->values)->toBe([])
        ->and($set->warnings)->toBe(['Secret scrubbing limited: could not read .env for site 9.'])
        ->and(Cache::has('forge-mcp:secrets:acme:9'))->toBeFalse();
});

it('forServer returns an incomplete set when sites cannot be listed', function () {
    Http::fake([SECRETS_SERVER.'/sites*' => Http::response([], 500)]);

    $set = SiteSecrets::forServer(new ForgeClient('tkn'), 'acme', 1);

    expect($set->complete)->toBeFalse()
        ->and($set->warnings)->toBe(['Secret scrubbing limited: could not list sites for server 1.']);
});
