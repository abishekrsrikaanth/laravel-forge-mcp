<?php

declare(strict_types=1);

namespace App\Security;

use App\Forge\ForgeClient;
use App\Forge\ForgeException;
use App\Support\EnvFile;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * Loads the secrets to scrub for a site (its parsed .env) with an encrypted per-site cache.
 *
 * Forge API cost (rate limit: 60 requests/minute):
 *  - forSite(): 0 requests when cached, otherwise 1 (GET environment).
 *  - forServer(): 1 (GET sites, itself cached by ForgeClient for `list_cache_ttl`) plus 1 per site
 *    whose .env is not cached, capped at MAX_FETCHES_PER_CALL. Sites past the cap are reported via
 *    an incomplete set + warning; because fetched sites are cached, repeated calls converge.
 *
 * A failed read never throws: it yields an incomplete set with a warning (and is not cached).
 */
final class SiteSecrets
{
    /** Maximum uncached .env fetches one forServer() call may make. */
    public const MAX_FETCHES_PER_CALL = 10;

    public static function forSite(ForgeClient $client, string $org, int $serverId, int $siteId): SecretSet
    {
        $cached = self::cached($org, $siteId);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $env = EnvFile::parse($client->environment($org, $serverId, $siteId));
        } catch (ForgeException) {
            return SecretSet::incomplete("Secret scrubbing limited: could not read .env for site {$siteId}.");
        }

        self::remember($org, $siteId, $env);

        return SecretSet::of($env);
    }

    /**
     * Seed the encrypted cache for a site with every value of every env given (key => value each),
     * e.g. the old and the new contents after an edit, so rotated-out and new secrets both stay
     * masked. Earlier envs win the label when two keys share a value.
     *
     * @param  array<string, string>  ...$envs
     */
    public static function remember(string $org, int $siteId, array ...$envs): void
    {
        $pairs = [];
        foreach ($envs as $env) {
            foreach ($env as $key => $value) {
                $pairs[] = [(string) $key, (string) $value];
            }
        }

        try {
            Cache::put(
                self::key($org, $siteId),
                Crypt::encryptString(json_encode(['v' => 2, 'pairs' => $pairs], JSON_THROW_ON_ERROR)),
                (int) config('forge-mcp.secrets_cache_ttl', 600),
            );
        } catch (JsonException) {
            // Not valid UTF-8: use it for this call, just don't cache it.
            self::forget($org, $siteId);
        }
    }

    public static function forServer(ForgeClient $client, string $org, int $serverId): SecretSet
    {
        try {
            $sites = $client->sites($org, $serverId);
        } catch (ForgeException) {
            return SecretSet::incomplete("Secret scrubbing limited: could not list sites for server {$serverId}.");
        }

        $siteIds = array_values(array_filter(
            array_map(static fn (array $site): int => (int) ($site['id'] ?? 0), $sites),
            static fn (int $id): bool => $id > 0,
        ));
        $set = SecretSet::empty();
        $fetches = 0;
        $skipped = 0;

        foreach ($siteIds as $siteId) {
            $cached = self::cached($org, $siteId);
            if ($cached !== null) {
                $set = $set->merge($cached);

                continue;
            }

            if ($fetches >= self::MAX_FETCHES_PER_CALL) {
                $skipped++;

                continue;
            }

            $fetches++;
            $set = $set->merge(self::forSite($client, $org, $serverId, $siteId));
        }

        if ($skipped > 0) {
            $total = count($siteIds);
            $set = $set->merge(SecretSet::incomplete(
                "Secret scrubbing limited: .env not yet read for {$skipped} of {$total} sites on server {$serverId}; retry shortly.",
            ));
        }

        return $set;
    }

    public static function forget(string $org, int $siteId): void
    {
        Cache::forget(self::key($org, $siteId));
    }

    /** The secrets already cached for a site, or null; never calls Forge and never fills the cache. */
    public static function peek(string $org, int $siteId): ?SecretSet
    {
        return self::cached($org, $siteId);
    }

    private static function key(string $org, int $siteId): string
    {
        return "forge-mcp:secrets:{$org}:{$siteId}";
    }

    /** The cached set for a site: the `pairs` format written by remember(), or a plain key => value map. */
    private static function cached(string $org, int $siteId): ?SecretSet
    {
        $payload = Cache::get(self::key($org, $siteId));
        if (! is_string($payload)) {
            return null;
        }

        try {
            $env = json_decode(Crypt::decryptString($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($env)) {
            return null;
        }

        if (($env['v'] ?? null) === 2 && is_array($env['pairs'] ?? null)) {
            $set = SecretSet::empty();
            foreach ($env['pairs'] as $pair) {
                if (is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)) {
                    $set = $set->merge(SecretSet::of([$pair[0] => $pair[1]]));
                }
            }

            return $set;
        }

        $values = [];
        foreach ($env as $key => $value) {
            if (is_string($value)) {
                $values[(string) $key] = $value;
            }
        }

        return SecretSet::of($values);
    }
}
