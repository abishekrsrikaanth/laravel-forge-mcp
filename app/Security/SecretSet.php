<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Known secret values to mask, plus whether the set is known to be complete.
 *
 * `$values` maps each candidate secret VALUE to the env KEY it came from (value => key), so sets
 * from several sites can be merged without one site's `DB_PASSWORD` overwriting another's.
 * Values are unfiltered candidates; {@see SecretScrubber} applies the skip rules.
 */
final readonly class SecretSet
{
    /**
     * @param  array<string, string>  $values  secret value => env key
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $values = [],
        public bool $complete = true,
        public array $warnings = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * Build a set from env-style pairs (key => value), e.g. the result of EnvFile::parse().
     *
     * @param  array<string, string>  $values  env key => value
     */
    public static function of(array $values): self
    {
        $byValue = [];
        foreach ($values as $key => $value) {
            $value = (string) $value;
            if ($value !== '' && ! isset($byValue[$value])) {
                $byValue[$value] = (string) $key;
            }
        }

        return new self($byValue);
    }

    public static function incomplete(string $warning): self
    {
        return new self([], false, [$warning]);
    }

    public function merge(self $other): self
    {
        return new self(
            $this->values + $other->values,
            $this->complete && $other->complete,
            array_values(array_unique([...$this->warnings, ...$other->warnings])),
        );
    }
}
