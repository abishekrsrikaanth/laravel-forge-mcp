<?php

use App\Support\EnvFile;
use Tests\TestCase;

uses(TestCase::class);

it('parses the common dotenv forms', function (string $input, array $expected) {
    expect(EnvFile::parse($input))->toBe($expected);
})->with([
    'plain' => ["A=1\nB=two\n", ['A' => '1', 'B' => 'two']],
    'double quoted with spaces' => ['A="hello world"', ['A' => 'hello world']],
    'single quoted' => ["A='hello world'", ['A' => 'hello world']],
    'hash inside quotes kept' => ['A="a # b"', ['A' => 'a # b']],
    'hash inside single quotes kept' => ["A='a # b'", ['A' => 'a # b']],
    'inline comment on unquoted' => ['A=value # note', ['A' => 'value']],
    'inline comment after quoted' => ['A="v w" # note', ['A' => 'v w']],
    'hash without space is part of value' => ['A=ab#cd', ['A' => 'ab#cd']],
    'export prefix' => ["export A=1\nexport B=\"x y\"", ['A' => '1', 'B' => 'x y']],
    'empty value' => ["A=\nB=''\nC=\"\"", ['A' => '', 'B' => '', 'C' => '']],
    'comments and blanks' => ["# c\n\nA=1\n  # indented\n", ['A' => '1']],
    'crlf' => ["A=1\r\nB=\"x y\"\r\n", ['A' => '1', 'B' => 'x y']],
    'equals in value' => ['A=a=b=c', ['A' => 'a=b=c']],
    'escapes in double quotes' => ['A="q\\"x\\\\y\\n"', ['A' => "q\"x\\y\n"]],
    'single quotes are literal' => ["A='\$x\\n'", ['A' => '$x\\n']],
    'last duplicate wins' => ["A=1\nA=2", ['A' => '2']],
    'unterminated quote does not swallow file' => ["A=\"oops\nB=2", ['A' => '"oops', 'B' => '2']],
]);

it('parses multi-line double-quoted values', function () {
    $pem = "-----BEGIN KEY-----\nabc\ndef\n-----END KEY-----";
    $env = "A=1\nKEY=\"{$pem}\"\nB=2\n";

    expect(EnvFile::parse($env))->toBe(['A' => '1', 'KEY' => $pem, 'B' => '2']);
    expect(EnvFile::parse(str_replace("\n", "\r\n", $env))['KEY'])->toBe($pem);
});

it('keeps comments, order and untouched lines while changing in place', function () {
    $env = "# top\nA=1\n\n# mid\nB=\"x y\"  # trailing\nC=3\n";

    $change = EnvFile::apply($env, ['A' => '9'], []);

    expect($change->contents)->toBe("# top\nA=9\n\n# mid\nB=\"x y\"  # trailing\nC=3\n")
        ->and($change->changed)->toBe(['A'])
        ->and($change->added)->toBe([])
        ->and($change->removed)->toBe([]);
});

it('does not report unchanged values and leaves their line byte-identical', function () {
    $env = "A=\"x y\"  # keep\nB=2\n";

    $change = EnvFile::apply($env, ['A' => 'x y'], []);

    expect($change->contents)->toBe($env)->and($change->changed)->toBe([]);
});

it('appends new keys and removes keys', function () {
    $change = EnvFile::apply("A=1\nB=2\nC=3\n", ['D' => '4'], ['B']);

    expect($change->contents)->toBe("A=1\nC=3\nD=4\n")
        ->and($change->added)->toBe(['D'])
        ->and($change->removed)->toBe(['B']);
});

it('treats removing a missing key as a no-op', function () {
    $change = EnvFile::apply("A=1\n", [], ['NOPE']);

    expect($change->contents)->toBe("A=1\n")->and($change->removed)->toBe([]);
});

it('quotes values that need it and round-trips them', function (string $value, string $line) {
    $change = EnvFile::apply("A=1\n", ['K' => $value], []);

    expect($change->contents)->toBe("A=1\n{$line}\n");
    expect(EnvFile::parse($change->contents)['K'])->toBe($value);
})->with([
    'bare' => ['abc-1.2/x', 'K=abc-1.2/x'],
    'empty' => ['', 'K='],
    'space' => ['a b', 'K="a b"'],
    'hash' => ['a#b', 'K="a#b"'],
    'equals' => ['a=b', 'K="a=b"'],
    'double quote' => ['a"b', 'K="a\\"b"'],
    'backslash' => ['a\\b', 'K="a\\\\b"'],
    'dollar uses single quotes' => ['pa$$word${X}', "K='pa\$\$word\${X}'"],
    'dollar with single quote is escaped' => ["it's \$x", 'K="it\'s \\$x"'],
    'single quote only' => ["it's", 'K="it\'s"'],
]);

it('round-trips multi-line values and writes them verbatim', function () {
    $pem = "-----BEGIN-----\nabc\n-----END-----";
    $change = EnvFile::apply("A=1\n", ['KEY' => $pem], []);

    expect($change->contents)->toBe("A=1\nKEY=\"{$pem}\"\n");
    expect(EnvFile::parse($change->contents)['KEY'])->toBe($pem);
});

it('replaces and removes whole multi-line entries', function () {
    $env = "A=1\nKEY=\"-----BEGIN\nabc\n-----END\"\nB=2\n";

    expect(EnvFile::apply($env, ['KEY' => 'short'], [])->contents)->toBe("A=1\nKEY=short\nB=2\n");
    expect(EnvFile::apply($env, [], ['KEY'])->contents)->toBe("A=1\nB=2\n");
});

it('updates the last duplicate and drops earlier ones; remove deletes all', function () {
    $env = "A=1\nB=x\nA=2\n";

    $change = EnvFile::apply($env, ['A' => '3'], []);
    expect($change->contents)->toBe("B=x\nA=3\n")->and($change->changed)->toBe(['A']);

    expect(EnvFile::apply($env, [], ['A'])->contents)->toBe("B=x\n");
});

it('preserves a missing trailing newline', function () {
    expect(EnvFile::apply('A=1', ['B' => '2'], [])->contents)->toBe("A=1\nB=2")
        ->and(EnvFile::apply('A=1', ['A' => '2'], [])->contents)->toBe('A=2')
        ->and(EnvFile::apply("A=1\n", ['B' => '2'], [])->contents)->toBe("A=1\nB=2\n");
});

it('preserves CRLF line endings', function () {
    $change = EnvFile::apply("A=1\r\n# c\r\nB=2\r\n", ['A' => 'x y', 'C' => 'new'], ['B']);

    expect($change->contents)->toBe("A=\"x y\"\r\n# c\r\nC=new\r\n");
});

it('uses file line endings inside appended multi-line values', function () {
    $change = EnvFile::apply("A=1\r\n", ['K' => "l1\nl2"], []);

    expect($change->contents)->toBe("A=1\r\nK=\"l1\r\nl2\"\r\n");
    expect(EnvFile::parse($change->contents)['K'])->toBe("l1\nl2");
});

it('preserves export prefix when changing a value', function () {
    expect(EnvFile::apply("export A=1\n", ['A' => '2'], [])->contents)->toBe("export A=2\n");
});

it('creates content from an empty file', function () {
    expect(EnvFile::apply('', ['A' => '1'], [])->contents)->toBe("A=1\n");
});

it('rejects invalid keys', function (string $key) {
    expect(fn () => EnvFile::apply("A=1\n", [$key => 'x'], []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => EnvFile::apply("A=1\n", [], [$key]))->toThrow(InvalidArgumentException::class);
})->with(['1ABC', 'A-B', 'A B', '', 'A=B', "A\nB"]);

it('rejects a key that is both set and removed', function () {
    expect(fn () => EnvFile::apply("A=1\n", ['A' => '2'], ['A']))->toThrow(InvalidArgumentException::class);
});
