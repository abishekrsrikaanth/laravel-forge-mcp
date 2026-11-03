<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Masks secrets in text before it is returned to an agent.
 *
 * Two passes:
 *  1. Known values (from a site's .env) become `[REDACTED:KEY]`. Each value is also matched in its
 *     JSON-escaped and URL-encoded forms, and `base64:` values (APP_KEY) also mask their payload.
 *     All forms are replaced in one longest-first pass (strtr), so a short value never splits a
 *     longer one and replacements are never re-scanned.
 *  2. Well-known token formats and `secret-ish key = value` assignments become `[REDACTED]`,
 *     leaving the key visible and leaving values already masked by pass 1 untouched.
 */
final class SecretScrubber
{
    private const NON_SECRET_VALUES = ['true', 'false', 'null', 'local', 'production', 'staging', 'testing'];

    private const PUBLIC_KEY = '/^(?:APP_URL|APP_NAME|APP_ENV|APP_DEBUG|.*_HOST|.*_PORT|.*_DRIVER|.*_CONNECTION)$/i';

    private const VAR_REFERENCE = '/^\$\{[A-Za-z_][A-Za-z0-9_.]*\}$/';

    /** Assignment values that are flags/placeholders rather than secrets. */
    private const PLACEHOLDER_VALUE = '/^(?:yes|no|true|false|null|none|undefined|\*+|\$\{[A-Za-z_][A-Za-z0-9_.]*\}|\[REDACTED.*)$/i';

    private const SECRET_KEY = '(?:KEY|SECRET|TOKEN|PASSWORD|PASS)';

    /** Token formats replaced outright with [REDACTED]. */
    private const TOKEN_PATTERNS = [
        // PEM private key block (raw newlines or JSON-escaped \n).
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/s',
        // Truncated PEM block (no END marker): the header plus the base64 lines that follow it.
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----(?:(?:\r?\n|\\\\r\\\\n|\\\\n)[A-Za-z0-9+\/=]+)*/',
        // JWT.
        '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]*/',
        // AWS access key id.
        '/\bAKIA[0-9A-Z]{16}\b/',
        // Stripe live secret / restricted keys.
        '/\b[sr]k_live_[A-Za-z0-9]{10,}/',
        // GitHub tokens.
        '/\bghp_[A-Za-z0-9]{20,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/',
        // Slack tokens.
        '/\bxox[baprs]-[A-Za-z0-9-]{10,}/',
        // OpenAI-style sk- keys (at least 20 characters after the prefix).
        '/(?<![A-Za-z0-9_-])sk-[A-Za-z0-9_-]{20,}/',
        // Laravel Sanctum plain-text tokens: {id}|{40+ chars}.
        '/\b\d+\|[A-Za-z0-9]{40,}\b/',
    ];

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
                foreach ($this->variants($candidate) as $variant) {
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
     * The value as it may appear in logs: raw, JSON-escaped (with and without escaped slashes and
     * unicode), and URL-encoded (RFC 3986 and form encoding).
     *
     * @return list<string>
     */
    private function variants(string $value): array
    {
        $forms = [$value, rawurlencode($value), urlencode($value)];

        foreach ([0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
            $json = json_encode($value, $flags);
            if (is_string($json)) {
                $forms[] = substr($json, 1, -1);
            }
        }

        return array_values(array_unique($forms));
    }

    private function maskPatterns(string $text): string
    {
        $text = preg_replace(self::TOKEN_PATTERNS, '[REDACTED]', $text) ?? $text;

        // Credentials in URLs: scheme://user:pass@host -> scheme://[REDACTED]@host.
        $text = preg_replace_callback(
            '~\b([a-z][a-z0-9+.\-]*://)([^\s/:@"\'<>]+):([^\s/?#"\'<>]+)@~i',
            fn (array $m): string => $this->isPlaceholder($m[3]) ? $m[0] : $m[1].'[REDACTED]@',
            $text,
        ) ?? $text;

        // "secret_key": "value"  (JSON).
        $text = preg_replace_callback(
            '/("[^"\\\\\r\n]*'.self::SECRET_KEY.'"[ \t]*:[ \t]*)"((?:[^"\\\\\r\n]|\\\\.)*)"/i',
            fn (array $m): string => $this->isPlaceholder($m[2]) ? $m[0] : $m[1].'"[REDACTED]"',
            $text,
        ) ?? $text;

        // SECRET_KEY=value and Secret-Key: value (env lines, query strings, headers, YAML). A bare
        // value may contain closing brackets but does not end with one, so `(token=abc)` keeps its
        // `)` while `token=ab]cd` is masked whole.
        return preg_replace_callback(
            '/(?<![A-Za-z0-9_.\-])([A-Za-z0-9_.\-]*'.self::SECRET_KEY.')([ \t]*[=:][ \t]*)(?![=>])'
                .'("(?:[^"\\\\\r\n]|\\\\.)*"|\'[^\'\r\n]*\'|\$\{[A-Za-z_][A-Za-z0-9_.]*\}|[^\s,;&"\'<>]*[^\s,;&"\'<>)\]}])/i',
            function (array $m): string {
                $value = $m[3];
                $bare = strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") ? substr($value, 1, -1) : $value;

                return $this->isPlaceholder($bare) ? $m[0] : $m[1].$m[2].'[REDACTED]';
            },
            $text,
        ) ?? $text;
    }

    private function isPlaceholder(string $value): bool
    {
        return $value === '' || preg_match(self::PLACEHOLDER_VALUE, $value) === 1;
    }
}
