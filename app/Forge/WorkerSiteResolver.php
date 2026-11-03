<?php

declare(strict_types=1);

namespace App\Forge;

/** Forge does not say which site a background process belongs to; infer it from its directory. */
final class WorkerSiteResolver
{
    /**
     * Sites a worker may belong to, for permission checks. Forge does not say, so this fails closed:
     * an explicit site id wins; else the single longest site root containing the worker directory;
     * else every site whose root lies under the directory (a worker run from `.../current` with the
     * site root at `.../current/public`); else none, meaning server-level rules apply.
     *
     * @param  array<string, mixed>  $worker  a flattened background-process record
     * @return list<int>
     */
    public static function candidateSiteIds(ForgeClient $client, string $org, int $serverId, array $worker): array
    {
        if (isset($worker['site_id']) && (int) $worker['site_id'] > 0) {
            return [(int) $worker['site_id']];
        }

        $directory = self::normalise((string) ($worker['directory'] ?? ''));

        if ($directory === '') {
            return [];
        }

        $containing = null;
        $containingLength = 0;
        $below = [];

        foreach ($client->sites($org, $serverId) as $site) {
            $root = self::normalise((string) ($site['root_directory'] ?? ''));
            $siteId = (int) ($site['id'] ?? 0);

            if ($root === '' || $siteId <= 0) {
                continue;
            }

            if (($directory === $root || str_starts_with($directory, $root.'/')) && strlen($root) > $containingLength) {
                $containing = $siteId;
                $containingLength = strlen($root);
            } elseif (str_starts_with($root, $directory.'/')) {
                $below[] = $siteId;
            }
        }

        return $containing !== null ? [$containing] : $below;
    }

    /** Trailing slashes removed; the filesystem root normalises to '' (matches nothing). */
    private static function normalise(string $path): string
    {
        return rtrim(trim($path), '/');
    }
}
