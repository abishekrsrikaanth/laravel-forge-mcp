<?php

namespace App\Permissions;

/**
 * What a capability is being exercised on. Organization slugs are compared exactly (case-sensitive).
 *
 * A site target should carry its server id: without it, server-scoped rules cannot match and resolution
 * falls through to organization/global rules. A target with a server or site id but no organization is
 * always denied.
 */
final readonly class Target
{
    public function __construct(
        public ?string $organization = null,
        public ?int $serverId = null,
        public ?int $siteId = null,
    ) {}

    public function describe(): string
    {
        $org = $this->organization ?? 'unknown organization';

        return match (true) {
            $this->siteId !== null && $this->serverId !== null => "site {$this->siteId} on server {$this->serverId} ({$org})",
            $this->siteId !== null => "site {$this->siteId} ({$org})",
            $this->serverId !== null => "server {$this->serverId} ({$org})",
            $this->organization !== null => "organization {$this->organization}",
            default => 'all organizations',
        };
    }
}
