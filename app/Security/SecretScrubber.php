<?php

declare(strict_types=1);

namespace App\Security;

use Closure;

/**
 * Masks secrets in text before it is returned to an agent.
 *
 * Two passes:
 *  1. Known values (from a site's .env) become `[REDACTED:KEY]`. Each value is also matched in the
 *     forms it takes in logs: JSON-escaped, URL-encoded, SQL/PHP single-quote escaped, and as PHP
 *     prints it in a stack-trace argument (escaped, truncated to zend.exception_string_param_max_len
 *     and suffixed with `...`). `base64:` values (APP_KEY) also mask their payload. All forms are
 *     replaced in one longest-first pass (strtr), so a short value never splits a longer one and
 *     replacements are never re-scanned.
 *  2. Well-known token formats and `secret-ish key = value` assignments become `[REDACTED]`,
 *     leaving the key visible and leaving values already masked by pass 1 untouched.
 *
 * Pass 2 fails CLOSED: patterns use possessive quantifiers so they stay linear on huge
 * whitespace-free runs, and if PCRE still errors (backtrack/JIT limits) the text is rescanned line
 * by line and any line that cannot be scanned is replaced with `[REDACTED: unscannable]`.
 */
final class SecretScrubber
{
    public const UNSCANNABLE = '[REDACTED: unscannable]';

    private const NON_SECRET_VALUES = ['true', 'false', 'null', 'local', 'production', 'staging', 'testing'];

    private const PUBLIC_KEY = '/^(?:APP_URL|APP_NAME|APP_ENV|APP_DEBUG|.*_HOST|.*_PORT|.*_DRIVER|.*_CONNECTION)$/i';

    private const VAR_REFERENCE = '/^\$\{[A-Za-z_][A-Za-z0-9_.]*\}$/';

    /** Assignment values that are flags/placeholders rather than secrets. */
    private const PLACEHOLDER_VALUE = '/^(?:yes|no|true|false|null|none|undefined|\*+|\$\{[A-Za-z_][A-Za-z0-9_.]*\}|\[REDACTED.*)$/i';

    /** Lookbehind asserting the key just matched ends in a secret-ish word. */
    private const SECRET_KEY_END = '(?<=KEY|SECRET|TOKEN|PASSWORD|PASS)';

    /** PHP's default for zend.exception_string_param_max_len. */
    private const DEFAULT_TRACE_ARG_LENGTH = 15;

    /** Token formats replaced outright with [REDACTED]. */
    private const TOKEN_PATTERNS = [
        // JWT.
        '/\beyJ[A-Za-z0-9_-]{10,}+\.[A-Za-z0-9_-]{10,}+\.[A-Za-z0-9_-]*+/',
        // AWS access key id.
        '/\bAKIA[0-9A-Z]{16}\b/',
        // Stripe live secret / restricted keys.
        '/\b[sr]k_live_[A-Za-z0-9]{10,}+/',
        // GitHub tokens.
        '/\bghp_[A-Za-z0-9]{20,}+/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}+/',
        // Slack tokens.
        '/\bxox[baprs]-[A-Za-z0-9-]{10,}+/',
        // OpenAI-style sk- keys (at least 20 characters after the prefix).
        '/(?<![A-Za-z0-9_-])sk-[A-Za-z0-9_-]{20,}+/',
        // Laravel Sanctum plain-text tokens: {id}|{40+ chars}.
        '/\b\d++\|[A-Za-z0-9]{40,}+\b/',
    ];

    /** scheme://user:pass@ — a password may contain '@' as long as another '@' follows it. */
    private const URL_CREDENTIALS = '~\b([a-z][a-z0-9+.\-]*+://)([^\s/:@"\'<>]++):((?:[^\s/?#"\'<>@]++|@(?=[^\s/?#"\'<>@]*+@))++)@~i';

    /** "secret_key": "value" (JSON). */
    private const JSON_ASSIGNMENT = '/("[^"\\\\\r\n]*+'.self::SECRET_KEY_END.'"[ \t]*+:[ \t]*+)"((?:[^"\\\\\r\n]++|\\\\.)*+)"/i';

    /**
     * SECRET_KEY=value and Secret-Key: value (env lines, query strings, headers, YAML). A bare
     * value may contain closing brackets but does not end with one, so `(token=abc)` keeps its
     * `)` while `token=ab]cd` is masked whole.
     */
    private const ASSIGNMENT = '/(?<![A-Za-z0-9_.\-])([A-Za-z0-9_.\-]++)'.self::SECRET_KEY_END.'([ \t]*+[=:][ \t]*+)(?![=>])'
        .'("(?:[^"\\\\\r\n]++|\\\\.)*+"|\'[^\'\r\n]*+\'|\$\{[A-Za-z_][A-Za-z0-9_.]*+\}|[^\s,;&"\'<>]*[^\s,;&"\'<>)\]}])/i';

    /** `--password=value`, `--password value` (also `--db-password`), bare, single- or double-quoted. */
    private const PASSWORD_FLAG = '/(--(?:[a-z]++-)?password(?:=|[ \t]++))(?!\[REDACTED)("[^"\r\n]*+"|\'[^\'\r\n]*+\'|[^\s"\']++)/i';

    /** A mysql-family command up to the end of the pipeline segment; `-p<value>` is masked inside it. */
    private const MYSQL_COMMAND = '/\b(?:mysql|mysqldump|mysqladmin|mysqlcheck|mysqlimport|mysqlshow|mariadb|mariadb-dump|mariadb-admin)\b[^\r\n|;&]*+/';

    private const MYSQL_PASSWORD = '/(?<=\s)-p(?!\[REDACTED)(?=\S)("[^"\r\n]*+"|\'[^\'\r\n]*+\'|[^\s"\']++)/';

    public function scrub(string $text, SecretSet $secrets): string
    {
        if ($text === '') {
            return $text;
        }

        $map = $this->replacements($secrets);
        if ($map !== []) {
            $text = strtr($text, $map);
        }

        return $this->maskPatterns($text);
    }

    /**
     * Every masked form of every known secret => its replacement label.
     *
     * @return array<string, string>
     */
    private function replacements(SecretSet $secrets): array
    {
        $min = max(1, (int) config('forge-mcp.scrub_min_length', 8));
        $traceLength = (int) ini_get('zend.exception_string_param_max_len');
        if ($traceLength <= 0) {
            $traceLength = self::DEFAULT_TRACE_ARG_LENGTH;
        }

        $map = [];

        foreach ($secrets->values as $value => $key) {
            $value = (string) $value;
            $key = (string) $key;

            if (! $this->isSecret($key, $value, $min)) {
                continue;
            }

            $candidates = [$value];
            if (str_starts_with($value, 'base64:')) {
                $candidates[] = substr($value, strlen('base64:'));
            }

            foreach ($candidates as $candidate) {
                foreach ($this->variants($candidate, $traceLength) as $variant) {
                    if (strlen($variant) >= $min && ! isset($map[$variant])) {
                        $map[$variant] = '[REDACTED:'.$key.']';
                    }
                }
            }
        }

        return $map;
    }

    private function isSecret(string $key, string $value, int $min): bool
    {
        return strlen($value) >= $min
            && ! in_array(strtolower($value), self::NON_SECRET_VALUES, true)
            && preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value) !== 1
            && preg_match(self::PUBLIC_KEY, $key) !== 1
            && preg_match(self::VAR_REFERENCE, $value) !== 1;
    }

    /**
     * The value as it may appear in logs.
     *
     * @return list<string>
     */
    private function variants(string $value, int $traceLength): array
    {
        // Literal forms: raw, SQL-escaped ('') and PHP-escaped (\') quotes, and the way
        // Throwable::getTraceAsString() prints a string argument (escaped, maybe truncated).
        $literals = [$value, $this->traceEscape($value)];
        if (str_contains($value, "'")) {
            $literals[] = str_replace("'", "''", $value);
            $literals[] = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
        }
        if (strlen($value) > $traceLength) {
            $literals[] = $this->traceEscape(substr($value, 0, $traceLength)).'...';
        }

        $forms = [rawurlencode($value), urlencode($value)];
        foreach ($literals as $literal) {
            $forms[] = $literal;

            // JSON-escaped (with and without escaped slashes and unicode).
            foreach ([0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
                $json = json_encode($literal, $flags);
                if (is_string($json)) {
                    $forms[] = substr($json, 1, -1);
                }
            }
        }

        return array_values(array_unique($forms));
    }

    /** Escape a string the way PHP's smart_str_append_escaped() does for stack-trace arguments. */
    private function traceEscape(string $value): string
    {
        $named = ["\n" => '\n', "\r" => '\r', "\t" => '\t', "\f" => '\f', "\v" => '\v', "\e" => '\e', '\\' => '\\\\'];
        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $ord = ord($char);
            $out .= match (true) {
                isset($named[$char]) => $named[$char],
                $ord < 32 || $ord > 126 => sprintf('\\x%02X', $ord),
                default => $char,
            };
        }

        return $out;
    }

    private function maskPatterns(string $text): string
    {
        $text = $this->maskPrivateKeys($text);

        $text = $this->guarded($text, fn (string $t): ?string => preg_replace(self::TOKEN_PATTERNS, '[REDACTED]', $t));

        $text = $this->guarded($text, fn (string $t): ?string => preg_replace_callback(
            self::URL_CREDENTIALS,
            fn (array $m): string => $this->isPlaceholder($m[3]) ? $m[0] : $m[1].'[REDACTED]@',
            $t,
        ));

        $text = $this->guarded($text, fn (string $t): ?string => preg_replace_callback(
            self::JSON_ASSIGNMENT,
            fn (array $m): string => $this->isPlaceholder($m[2]) ? $m[0] : $m[1].'"[REDACTED]"',
            $t,
        ));

        $text = $this->guarded($text, fn (string $t): ?string => preg_replace(self::PASSWORD_FLAG, '$1[REDACTED]', $t));

        $text = $this->guarded($text, fn (string $t): ?string => preg_replace_callback(
            self::MYSQL_COMMAND,
            fn (array $m): string => preg_replace(self::MYSQL_PASSWORD, '-p[REDACTED]', $m[0]) ?? self::UNSCANNABLE,
            $t,
        ));

        return $this->guarded($text, fn (string $t): ?string => preg_replace_callback(
            self::ASSIGNMENT,
            function (array $m): string {
                $value = $m[3];
                $bare = strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") ? substr($value, 1, -1) : $value;

                return $this->isPlaceholder($bare) ? $m[0] : $m[1].$m[2].'[REDACTED]';
            },
            $t,
        ));
    }

    /**
     * Run a regex step over the whole text; if PCRE fails, rerun it line by line and replace each
     * line it still cannot scan. Never returns unscanned text.
     *
     * @param  Closure(string): ?string  $step
     */
    private function guarded(string $text, Closure $step): string
    {
        $result = $step($text);
        if ($result !== null) {
            return $result;
        }

        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            $lines[$i] = $step($line) ?? self::UNSCANNABLE;
        }

        return implode("\n", $lines);
    }

    /**
     * Replace PEM private key blocks (raw or JSON-escaped newlines) with [REDACTED]. A block runs to
     * its END marker; without one (truncated output) it covers the header plus the base64 lines
     * after it. Uses strpos + anchored regexes so it is linear in the text size; on a PCRE error the
     * rest of the text is replaced (fail closed).
     */
    private function maskPrivateKeys(string $text): string
    {
        if (! str_contains($text, 'PRIVATE KEY-----')) {
            return $text;
        }

        $out = '';
        $offset = 0;
        $marker = -1; // cached position of the next "-----END " (false: none left), so the scan stays linear

        while (($start = strpos($text, '-----BEGIN ', $offset)) !== false) {
            $header = preg_match('/\G-----BEGIN [A-Z0-9 ]{0,40}PRIVATE KEY-----/', $text, $m, 0, $start);

            if ($header === 0) {
                $out .= substr($text, $offset, $start + 1 - $offset);
                $offset = $start + 1;

                continue;
            }

            $end = $header === 1 ? $this->privateKeyEnd($text, $start + strlen($m[0]), $marker) : null;
            if ($end === null) {
                return $out.substr($text, $offset, $start - $offset).self::UNSCANNABLE;
            }

            $out .= substr($text, $offset, $start - $offset).'[REDACTED]';
            $offset = $end;
        }

        return $out.substr($text, $offset);
    }

    /**
     * Offset just past the private key body starting at $from, or null on a PCRE error.
     * $marker caches the next "-----END " offset across calls (false once none remain).
     */
    private function privateKeyEnd(string $text, int $from, int|false &$marker): ?int
    {
        if ($marker !== false && $marker < $from) {
            $marker = strpos($text, '-----END ', $from);
        }

        if ($marker !== false) {
            $found = preg_match('/\G-----END [A-Z0-9 ]{0,40}PRIVATE KEY-----/', $text, $m, 0, $marker);
            if ($found === false) {
                return null;
            }
            if ($found === 1) {
                return $marker + strlen($m[0]);
            }
        }

        $found = preg_match('/\G(?:(?:\r?\n|\\\\r\\\\n|\\\\n)[A-Za-z0-9+\/=]++)*+/', $text, $m, 0, $from);

        return $found === false ? null : $from + strlen($m[0]);
    }

    private function isPlaceholder(string $value): bool
    {
        return $value === '' || preg_match(self::PLACEHOLDER_VALUE, $value) === 1;
    }
}
