<?php

declare(strict_types=1);

namespace App\Mcp\Support;

/** The defaults in effect for one tool call, and where they came from ('header', 'token' or null when there are none). */
final readonly class ResolvedDefaults
{
    public function __construct(
        public ?string $organization = null,
        public ?int $serverId = null,
        public ?int $siteId = null,
        public ?string $source = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
