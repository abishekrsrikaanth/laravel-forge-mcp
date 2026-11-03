<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Pure .env parser and line-preserving editor.
 *
 * Duplicate keys: `parse()` returns the last definition (as dotenv does). `apply()` updates the
 * LAST occurrence in place and deletes the earlier duplicates, so the file stays unambiguous;
 * `remove` deletes every occurrence.
 */
final class EnvFile
{
    private const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const ENTRY_PATTERN = '/^(?<prefix>\s*(?:export\s+)?(?<key>[A-Za-z_][A-Za-z0-9_.]*)\s*=)(?<rest>.*)$/s';

    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (self::entries($contents) as $entry) {
            if ($entry['key'] !== null) {
                $values[$entry['key']] = (string) $entry['value'];
            }
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $set
     * @param  list<string>  $remove
     */
    public static function apply(string $contents, array $set, array $remove = []): EnvChange
    {
        $normalizedSet = [];
        foreach ($set as $key => $value) {
            $normalizedSet[self::validKey((string) $key)] = str_replace(["\r\n", "\r"], "\n", (string) $value);
        }

        $normalizedRemove = array_values(array_unique(array_map(
            static fn (mixed $key): string => self::validKey((string) $key),
            $remove,
        )));

        if (array_intersect_key($normalizedSet, array_flip($normalizedRemove)) !== []) {
            throw new InvalidArgumentException('A key cannot be both set and removed.');
        }

        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $hadTrailingNewline = $contents === '' || str_ends_with($contents, "\n");

        $entries = self::entries($contents);
        $added = [];
        $changed = [];
        $removed = [];

        // Removals: drop every occurrence (the whole multi-line entry).
        foreach ($normalizedRemove as $key) {
            $before = count($entries);
            $entries = array_values(array_filter($entries, static fn (array $e): bool => $e['key'] !== $key));
            if (count($entries) !== $before) {
                $removed[] = $key;
            }
        }

        // Sets: update the last occurrence, drop earlier duplicates, append when missing.
        $appended = [];
        foreach ($normalizedSet as $key => $value) {
            $indexes = [];
            foreach ($entries as $i => $entry) {
                if ($entry['key'] === $key) {
                    $indexes[] = $i;
                }
            }

            if ($indexes === []) {
                $appended[] = $key.'='.self::format($value, $eol);
                $added[] = $key;

                continue;
            }

            $last = array_pop($indexes);
            foreach ($indexes as $i) {
                unset($entries[$i]);
            }

            if ($entries[$last]['value'] !== $value || self::mayInterpolate($entries[$last])) {
                $entries[$last]['raw'] = $entries[$last]['head'].self::format($value, $eol).$entries[$last]['eol'];
                $entries[$last]['value'] = $value;
                $changed[] = $key;
            }
        }

        $out = implode('', array_column($entries, 'raw'));

        if ($appended !== []) {
            if ($out !== '' && ! str_ends_with($out, "\n")) {
                $out .= $eol;
            }
            $out .= implode($eol, $appended).$eol;
        }

        if (! $hadTrailingNewline && $out !== '') {
            $out = preg_replace('/\r?\n\z/', '', $out) ?? $out;
        }

        return new EnvChange($out, $added, $changed, $removed);
    }

    /**
     * Whether the entry's raw (on-disk) value could be changed by dotenv's ${VAR} interpolation, so
     * its parsed value is not what the app sees and an "unchanged" write must still be rewritten.
     * Single-quoted values are literal and never interpolate.
     *
     * @param  array{raw: string, head: string}  $entry
     */
    private static function mayInterpolate(array $entry): bool
    {
        $raw = ltrim(substr($entry['raw'], strlen($entry['head'])));

        return str_contains($raw, '$') && ! str_starts_with($raw, "'");
    }

    private static function validKey(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid env key: {$key}");
        }

        return $key;
    }

    /**
     * Render a value for writing. Plain values stay bare; values with `$` go in single quotes
     * (stored literally, no ${VAR} interpolation) unless they contain a single quote.
     */
    private static function format(string $value, string $eol): string
    {
        if (preg_match('/^[A-Za-z0-9_.\/:@%+,\-]*$/', $value) === 1) {
            return $value;
        }

        if (str_contains($value, '$') && ! str_contains($value, "'")) {
            return "'".str_replace("\n", $eol, $value)."'";
        }

        $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);

        return '"'.str_replace("\n", $eol, $escaped).'"';
    }

    /**
     * Split the file into physical entries. Each has the raw text (with line ending(s)), and for
     * assignments: key, decoded value, `head` (everything up to and including `=`) and `eol`.
     *
     * @return list<array{raw: string, key: ?string, value: ?string, head: string, eol: string}>
     */
    private static function entries(string $contents): array
    {
        $lines = preg_split('/(?<=\n)/', $contents, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $entries = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (preg_match(self::ENTRY_PATTERN, $line, $m) !== 1) {
                $entries[] = ['raw' => $line, 'key' => null, 'value' => null, 'head' => '', 'eol' => ''];

                continue;
            }

            $rest = $m['rest'];
            $raw = $line;
            $trimmed = ltrim($rest);
            $quote = $trimmed[0] ?? '';

            if ($quote === '"' || $quote === "'") {
                $body = $trimmed;
                $j = $i;
                $end = self::closingQuote($body, $quote);
                while ($end === null && $j + 1 < $count) {
                    $j++;
                    $raw .= $lines[$j];
                    $body .= $lines[$j];
                    $end = self::closingQuote($body, $quote);
                }

                if ($end !== null) {
                    $inner = substr($body, 1, $end - 1);
                    $value = $quote === '"' ? self::unescape($inner) : $inner;
                    $i = $j;
                } else {
                    // Unterminated quote: fall back to treating this single line as unquoted.
                    $raw = $line;
                    $value = self::unquoted($rest);
                }
            } else {
                $value = self::unquoted($rest);
            }

            preg_match('/(\r?\n)?\z/', $raw, $eolMatch);

            $entries[] = [
                'raw' => $raw,
                'key' => $m['key'],
                'value' => str_replace(["\r\n", "\r"], "\n", $value),
                'head' => $m['prefix'],
                'eol' => $eolMatch[1] ?? '',
            ];
        }

        return $entries;
    }

    /** Offset of the closing quote in $body (whose first char is the opening quote), or null. */
    private static function closingQuote(string $body, string $quote): ?int
    {
        $length = strlen($body);
        for ($p = 1; $p < $length; $p++) {
            if ($quote === '"' && $body[$p] === '\\') {
                $p++;

                continue;
            }
            if ($body[$p] === $quote) {
                return $p;
            }
        }

        return null;
    }

    private static function unescape(string $inner): string
    {
        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\', '$' => '$'];

        return (string) preg_replace_callback(
            '/\\\\([nrt"\\\\$])/',
            static fn (array $m): string => $map[$m[1]],
            $inner,
        );
    }

    private static function unquoted(string $rest): string
    {
        // As phpdotenv: in an unquoted value `#` starts a comment even without preceding whitespace.
        $rest = rtrim($rest, "\r\n");
        $hash = strpos($rest, '#');
        if ($hash !== false) {
            $rest = substr($rest, 0, $hash);
        }

        return trim($rest);
    }
}
