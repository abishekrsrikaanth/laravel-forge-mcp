<?php

declare(strict_types=1);

namespace App\Forge;

/** Forge does not say which site a background process belongs to; infer it from its directory. */
final class WorkerSiteResolver
{
    /**
     * @param  array<string, mixed>  $worker  a flattened background-process record
     */
    public static function siteIdFor(ForgeClient $client, string $org, int $serverId, array $worker): ?int
    {
        if (isset($worker['site_id']) && (int) $worker['site_id'] > 0) {
            return (int) $worker['site_id'];
        }

        $directory = self::normalise((string) ($worker['directory'] ?? ''));

        if ($directory === '') {
            return null;
        }

        $bestId = null;
        $bestLength = 0;

        foreach ($client->sites($org, $serverId) as $site) {
            $root = self::normalise((string) ($site['root_directory'] ?? ''));
            $siteId = (int) ($site['id'] ?? 0);

            if ($root === '' || $siteId <= 0) {
                continue;
            }

            $matches = $directory === $root || str_starts_with($directory, $root.'/');

            if ($matches && strlen($root) > $bestLength) {
                $bestId = $siteId;
                $bestLength = strlen($root);
            }
        }

        return $bestId;
    }

    /** Trailing slashes removed; the filesystem root normalises to '' (matches nothing). */
    private static function normalise(string $path): string
    {
        return rtrim(trim($path), '/');
    }
}
