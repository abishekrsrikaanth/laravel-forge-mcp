<?php

namespace App\Permissions;

use InvalidArgumentException;

/**
 * What a capability is being exercised on. Organization slugs are compared exactly (case-sensitive).
 *
 * Targets must be built from ids Forge has resolved (the site's real server, the server's real
 * organization), never from organization/server/site pairs asserted by an agent: resolution trusts
 * that a site target's server id is the server the site actually lives on.
 *
 * A site target must carry its server id, otherwise server-scoped rules could be skipped; the
 * constructor rejects that shape. A target with a server or site id but no organization is always denied.
 */
final readonly class Target
{
    public function __construct(
        public ?string $organization = null,
        public ?int $serverId = null,
        public ?int $siteId = null,
    ) {
        if ($siteId !== null && $serverId === null) {
            throw new InvalidArgumentException("Site {$siteId} target needs the server id it lives on.");
        }
    }

    public function describe(): string
    {
        $org = $this->organization ?? 'unknown organization';

        return match (true) {
            $this->siteId !== null => "site {$this->siteId} on server {$this->serverId} ({$org})",
            $this->serverId !== null => "server {$this->serverId} ({$org})",
            $this->organization !== null => "organization {$this->organization}",
            default => 'all organizations',
        };
    }
}
