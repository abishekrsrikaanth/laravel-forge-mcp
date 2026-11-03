<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Result of {@see EnvFile::apply()}. The key lists hold key names only (never values).
 */
final readonly class EnvChange
{
    /**
     * @param  list<string>  $added
     * @param  list<string>  $changed
     * @param  list<string>  $removed
     */
    public function __construct(
        public string $contents,
        public array $added = [],
        public array $changed = [],
        public array $removed = [],
    ) {}
}
