<?php

use App\Security\SecretScrubber;
use App\Security\SecretSet;
use App\Support\EnvFile;
use Tests\TestCase;

uses(TestCase::class);

function scrubWith(string $text, array $env = []): string
{
    return (new SecretScrubber)->scrub($text, SecretSet::of($env));
}

it('masks known env values with the key name', function () {
    expect(scrubWith('connect s3cr3tPassw0rd failed', ['DB_PASSWORD' => 's3cr3tPassw0rd']))
        ->toBe('connect [REDACTED:DB_PASSWORD] failed');
});

it('masks values inside urls, json and stack traces', function (string $text, string $expected) {
    expect(scrubWith($text, ['DB_PASSWORD' => 's3cr3tPassw0rd']))->toBe($expected);
})->with([
    'url' => ['mysql://u:s3cr3tPassw0rd@h/db', 'mysql://u:[REDACTED:DB_PASSWORD]@h/db'],
    'json' => ['{"key":"s3cr3tPassw0rd"}', '{"key":"[REDACTED:DB_PASSWORD]"}'],
    'trace' => [
        "#0 /app/vendor/Connector.php(65): PDO->__construct('mysql:host=db', 'forge', 's3cr3tPassw0rd', Array)",
        "#0 /app/vendor/Connector.php(65): PDO->__construct('mysql:host=db', 'forge', '[REDACTED:DB_PASSWORD]', Array)",
    ],
]);

it('masks json-escaped and url-encoded forms of known values', function () {
    $env = ['REDIS_PASSWORD' => 'p/a"s s+w@rd!'];

    expect(scrubWith('{"pw":"p\/a\"s s+w@rd!"}', $env))->toBe('{"pw":"[REDACTED:REDIS_PASSWORD]"}')
        ->and(scrubWith('{"pw":"p/a\"s s+w@rd!"}', $env))->toBe('{"pw":"[REDACTED:REDIS_PASSWORD]"}')
        ->and(scrubWith('GET /x?pw=p%2Fa%22s%20s%2Bw%40rd%21', $env))->toBe('GET /x?pw=[REDACTED:REDIS_PASSWORD]')
        ->and(scrubWith('GET /x?q=p%2Fa%22s+s%2Bw%40rd%21', $env))->toBe('GET /x?q=[REDACTED:REDIS_PASSWORD]');
});

it('masks the base64 payload of APP_KEY as well as the full value', function () {
    $env = ['APP_KEY' => 'base64:Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmdoaWo='];

    expect(scrubWith('key base64:Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmdoaWo= end', $env))->toBe('key [REDACTED:APP_KEY] end')
        ->and(scrubWith('raw Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmdoaWo= end', $env))->toBe('raw [REDACTED:APP_KEY] end');
});

it('skips short and non-secret values', function () {
    $env = [
        'SHORT_SECRET' => 'abc1234',
        'FLAG_A' => 'production',
        'FLAG_B' => 'staging',
        'FLAG_C' => 'testing',
        'FLAG_D' => 'TRUE',
        'NUMBER' => '1234567890',
        'DECIMAL' => '12345.678',
        'APP_URL' => 'https://example-app.test',
        'APP_NAME' => 'ExampleApplication',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'falsefalse',
        'DB_HOST' => 'database.internal',
        'REDIS_PORT' => 'port-number-x',
        'CACHE_DRIVER' => 'memcached',
        'DB_CONNECTION' => 'pgsql-primary',
        'MAIL_PASSWORD' => '${DB_PASSWORD}',
    ];
    $text = 'abc1234 production staging testing TRUE 1234567890 12345.678 https://example-app.test ExampleApplication '
        .'falsefalse database.internal port-number-x memcached pgsql-primary ${DB_PASSWORD}';

    expect(scrubWith($text, $env))->toBe($text);
});

it('masks a value equal to the minimum length', function () {
    expect(scrubWith('x 12ab34cd y', ['SOME_SECRET' => '12ab34cd']))->toBe('x [REDACTED:SOME_SECRET] y');
});

it('matches longest values first', function () {
    $env = ['SHORT_TOKEN' => 'abcdefgh12', 'LONG_TOKEN' => 'abcdefgh1234567'];

    expect(scrubWith('a abcdefgh1234567 b abcdefgh12 c', $env))
        ->toBe('a [REDACTED:LONG_TOKEN] b [REDACTED:SHORT_TOKEN] c');
});

it('masks patterns without known values', function (string $text, string $expected) {
    expect(scrubWith($text))->toBe($expected);
})->with([
    'pem private key' => [
        "before\n-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAx4\nabcDEF+/=\n-----END RSA PRIVATE KEY-----\nafter",
        "before\n[REDACTED]\nafter",
    ],
    'pem json-escaped' => [
        '{"k":"-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBg\n-----END PRIVATE KEY-----"}',
        '{"k":"[REDACTED]"}',
    ],
    'pem truncated' => [
        "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\nAAAABG5vbmU",
        '[REDACTED]',
    ],
    'jwt' => [
        'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U done',
        'Authorization: Bearer [REDACTED] done',
    ],
    'url credentials' => ['proxy https://bob:hunter2pw@proxy.example.com:8080/x', 'proxy https://[REDACTED]@proxy.example.com:8080/x'],
    'aws access key' => ['id AKIAIOSFODNN7EXAMPLE used', 'id [REDACTED] used'],
    'stripe secret key' => ['key sk_'.'live_51H8abcDEFghiJKLmnoPQR end', 'key [REDACTED] end'],
    'stripe restricted key' => ['key rk_'.'live_51H8abcDEFghiJKLmnoPQR end', 'key [REDACTED] end'],
    'github classic token' => ['t ghp_1234567890abcdefghijABCDEFGHIJ123456 x', 't [REDACTED] x'],
    'github fine-grained token' => ['t github_pat_11ABCDEFG0123456789_abcdefghijklmnopqrstuvwxyz x', 't [REDACTED] x'],
    'slack token' => ['t xoxb-1234567890-0987654321-AbCdEfGhIjKl x', 't [REDACTED] x'],
    'openai style key' => ['t sk-proj-abcdefghijklmnopqrstuvwx x', 't [REDACTED] x'],
    'sanctum token' => ['t 12|abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN0123 x', 't [REDACTED] x'],
    'KEY=value' => ['MAILGUN_SECRET=abc123xyz STRIPE_KEY=pk_x', 'MAILGUN_SECRET=[REDACTED] STRIPE_KEY=[REDACTED]'],
    'query string token' => ['GET /hook?token=abc123&page=2 HTTP/1.1', 'GET /hook?token=[REDACTED]&page=2 HTTP/1.1'],
    'KEY: value' => ['X-Api-Key: abc123def', 'X-Api-Key: [REDACTED]'],
    'quoted KEY=value' => ['DB_PASSWORD="two words"', 'DB_PASSWORD=[REDACTED]'],
    'value with inner bracket' => ['api_key=ab]c)d', 'api_key=[REDACTED]'],
    'value before a closing paren' => ['call(token=abc123)', 'call(token=[REDACTED])'],
    '"key": "value"' => [
        '{"password": "hunter22", "user": "bob", "api_token":"t0k"}',
        '{"password": "[REDACTED]", "user": "bob", "api_token":"[REDACTED]"}',
    ],
]);

it('does not double-mask known values that sit in an assignment or url', function () {
    $env = ['DB_PASSWORD' => 's3cr3tPassw0rd'];

    expect(scrubWith('DB_PASSWORD=s3cr3tPassw0rd', $env))->toBe('DB_PASSWORD=[REDACTED:DB_PASSWORD]')
        ->and(scrubWith('{"password":"s3cr3tPassw0rd"}', $env))->toBe('{"password":"[REDACTED:DB_PASSWORD]"}')
        ->and(scrubWith('https://u:s3cr3tPassw0rd@h', $env))->toBe('https://u:[REDACTED:DB_PASSWORD]@h');
});

it('does not mask non-secret assignment keys or flag values', function () {
    $text = 'PASSWORD_TIMEOUT=10800 (using password: YES) remember_token=null sk-short MAIL_PASSWORD=${DB_PASSWORD}';

    expect(scrubWith($text))->toBe($text);
});

it('leaves ordinary log lines unchanged', function (string $line) {
    expect(scrubWith($line, ['DB_PASSWORD' => 's3cr3tPassw0rd', 'APP_ENV' => 'production']))->toBe($line);
})->with([
    'laravel' => ['[2026-11-03 10:15:42] production.ERROR: Call to undefined method App\\Models\\User::foo() {"userId":42,"exception":"[object] (BadMethodCallException(code: 0): Call to undefined method App\\\\Models\\\\User::foo() at /home/forge/example.com/vendor/laravel/framework/src/Illuminate/Support/Traits/ForwardsCalls.php:67)"}'],
    'nginx access' => ['203.0.113.7 - - [03/Nov/2026:10:15:42 +0000] "GET /api/users?page=2&sort=name HTTP/2.0" 200 5123 "https://example.com/dashboard" "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"'],
    'deploy' => ['Installing dependencies from lock file (including require-dev)'],
]);

/** Mimics PDO's constructor: throws so the trace records the secret as a (truncated) argument. */
function scrubberTestConnect(string $dsn, string $username, string $password): never
{
    throw new RuntimeException('SQLSTATE[HY000] [1045] Access denied for user \'forge\'@\'10.0.0.5\' (using password: YES)');
}

/** Run $callback with ini settings applied, restoring them afterwards. */
function withIni(array $settings, Closure $callback): mixed
{
    $previous = [];
    foreach ($settings as $key => $value) {
        $previous[$key] = ini_set($key, $value);
    }

    try {
        return $callback();
    } finally {
        foreach ($previous as $key => $value) {
            if ($value !== false) {
                ini_set($key, $value);
            }
        }
    }
}

it('masks secrets in a real laravel stack trace (truncated args) and keeps the rest intact', function () {
    $env = EnvFile::parse(<<<'ENV'
        APP_NAME=Example
        APP_ENV=production
        APP_KEY=base64:dGhpc2lzYXRlc3RrZXl0aGlzaXNhdGVzdGtleTEyMzQ=
        APP_DEBUG=false
        APP_URL=https://example.com

        DB_CONNECTION=mysql
        DB_HOST=10.0.0.5
        DB_PORT=3306
        DB_DATABASE=forge
        DB_USERNAME=forge
        DB_PASSWORD="Xk9#mP2$vQ7!rT4zLongerThan15"
        ENV);
    $password = 'Xk9#mP2$vQ7!rT4zLongerThan15';
    $prefix = substr($password, 0, 15).'...';
    $jwt = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJhdWQiOiIxIiwianRpIjoiYWJjIiwic3ViIjoiNDIifQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
    $stripe = 'sk_'.'live_51NzQ2bKx9Yq7TrVw3Hj8LmPa0Sd';

    $trace = withIni(['zend.exception_ignore_args' => '0', 'zend.exception_string_param_max_len' => '15'], function () use ($password) {
        try {
            scrubberTestConnect('mysql:host=10.0.0.5;port=3306;dbname=forge', 'forge', $password);
        } catch (RuntimeException $e) {
            return $e;
        }
    });

    // Laravel's daily log format: message, JSON context, then the raw getTraceAsString().
    $log = "[2026-11-03 10:15:42] production.ERROR: {$trace->getMessage()} {\"exception\":\"[object] (RuntimeException(code: 0): "
        ."{$trace->getMessage()} at {$trace->getFile()}:{$trace->getLine()})\n[stacktrace]\n{$trace->getTraceAsString()}\n\"}\n"
        ."[2026-11-03 10:15:43] production.ERROR: Invalid API Key provided: {$stripe} {\"userId\":42}\n"
        ."[2026-11-03 10:15:44] production.WARNING: Rejected bearer {$jwt} for /api/me\n";

    expect($log)->toContain("'forge', '{$prefix}')"); // the real trace truncates the argument

    $scrubbed = (new SecretScrubber)->scrub($log, SecretSet::of($env));

    expect($scrubbed)->not->toContain(substr($password, 0, 15))
        ->not->toContain($stripe)
        ->not->toContain($jwt)
        ->toContain("'forge', '[REDACTED:DB_PASSWORD]')")
        ->toBe(str_replace(
            [$prefix, $stripe, $jwt],
            ['[REDACTED:DB_PASSWORD]', '[REDACTED]', '[REDACTED]'],
            $log,
        ));
});

it('masks the escaped and truncated forms php prints for trace arguments', function (string $value) {
    $trace = withIni(['zend.exception_ignore_args' => '0'], function () use ($value) {
        try {
            scrubberTestConnect('dsn', 'forge', $value);
        } catch (RuntimeException $e) {
            return $e->getTraceAsString();
        }
    });

    $scrubbed = (new SecretScrubber)->scrub($trace, SecretSet::of(['DB_PASSWORD' => $value]));

    expect($scrubbed)->toContain("'forge', '[REDACTED:DB_PASSWORD]')");
})->with([
    'short with escapes' => ["ab\\c\td\nef"],
    'long with escapes' => ["a\\b\nc\td\x7fefghijklmnopqrstuvwxyz"],
    'long plain' => ['Xk9mP2vQ7rT4zLongPassword'],
    'non-ascii' => ['pässwörd-ünïcode-1'],
]);

it('uses the configured trace argument length', function () {
    $scrubbed = withIni(['zend.exception_string_param_max_len' => '10'], fn () => scrubWith(
        "connect('abcdefghij...')",
        ['DB_PASSWORD' => 'abcdefghijKLMNOP'],
    ));

    expect($scrubbed)->toBe("connect('[REDACTED:DB_PASSWORD]')");
});

it('masks sql- and php-escaped forms of values with single quotes', function () {
    $env = ['DB_PASSWORD' => "p@ss'w0rd/x"];

    expect(scrubWith("update u set pw = 'p@ss''w0rd/x'", $env))->toBe("update u set pw = '[REDACTED:DB_PASSWORD]'")
        ->and(scrubWith("array('p@ss\\'w0rd/x')", $env))->toBe("array('[REDACTED:DB_PASSWORD]')");
});

it('still masks secrets next to huge whitespace-free runs without jit', function (string $blob) {
    $text = "[2026-11-03] local.INFO: payload {$blob}\n"
        ."redis://default:LeakedPw12345@h:6379 api_token=LeakedTok999999 {\"password\": \"LeakedJson777\"}\n";

    $scrubbed = withIni(['pcre.jit' => '0'], fn () => scrubWith($text));

    expect($scrubbed)->not->toContain('LeakedPw12345')
        ->not->toContain('LeakedTok999999')
        ->not->toContain('LeakedJson777')
        ->toContain($blob);
})->with([
    'no whitespace' => [str_repeat('A', 1_000_000)],
    'base64 image' => ['data:image/png;base64,'.str_repeat('iVBORw0KGgo', 100_000)],
    'long json string' => ['{"body":"'.str_repeat('x', 1_000_000).'"}'],
    'key-like chain' => [str_repeat('apiKEY', 200_000)],
    'url-like' => ['http://'.str_repeat('a', 500_000).':'.str_repeat('b', 500_000)],
]);

it('fails closed when a regex cannot scan the text', function () {
    $text = "first line api_token=LeakedTok999999\nsecond {\"password\": \"LeakedJson777\"}\nknown s3cr3tPassw0rd";

    $scrubbed = withIni(['pcre.jit' => '0', 'pcre.backtrack_limit' => '1'], fn () => scrubWith($text, ['DB_PASSWORD' => 's3cr3tPassw0rd']));

    expect($scrubbed)->not->toContain('LeakedTok999999')
        ->not->toContain('LeakedJson777')
        ->not->toContain('s3cr3tPassw0rd')
        ->toContain('[REDACTED: unscannable]');
});

it('merges secret sets, keeping completeness and warnings', function () {
    $a = SecretSet::of(['A_SECRET' => 'aaaaaaaaaa']);
    $b = new SecretSet(['bbbbbbbbbb' => 'B_SECRET'], false, ['w1']);

    $merged = $a->merge($b);

    expect($merged->complete)->toBeFalse()
        ->and($merged->warnings)->toBe(['w1'])
        ->and(SecretSet::empty()->complete)->toBeTrue()
        ->and((new SecretScrubber)->scrub('aaaaaaaaaa bbbbbbbbbb', $merged))->toBe('[REDACTED:A_SECRET] [REDACTED:B_SECRET]');
});

it('scans many unterminated private key headers in linear time', function () {
    $text = str_repeat("-----BEGIN RSA PRIVATE KEY-----\nAAAA\n", 50_000);

    $started = microtime(true);
    $scrubbed = scrubWith($text);

    expect(microtime(true) - $started)->toBeLessThan(5.0)
        ->and($scrubbed)->toBe(str_repeat("[REDACTED]\n", 50_000));
});

it('masks CLI password flags conservatively', function (string $text, string $expected) {
    expect(scrubWith($text))->toBe($expected);
})->with([
    'mysql -p' => ['mysql -uforge -pSecret123 db', 'mysql -uforge -p[REDACTED] db'],
    'mysqldump quoted' => ["mysqldump -u root -p'my pass' db > a.sql", 'mysqldump -u root -p[REDACTED] db > a.sql'],
    'mariadb' => ['mariadb -h x -pSecret123', 'mariadb -h x -p[REDACTED]'],
    '--password=' => ['tool --password=hunter22 run', 'tool --password=[REDACTED] run'],
    '--password space' => ['tool --password hunter22 run', 'tool --password [REDACTED] run'],
    'mkdir -p untouched' => ['mkdir -p storage/app/dir', 'mkdir -p storage/app/dir'],
    'mysql prompt untouched' => ['mysql -u forge -p db', 'mysql -u forge -p db'],
    'cp -p untouched' => ['cp -pr a b', 'cp -pr a b'],
]);
