<?php

use App\Mcp\Support\OutputTail;
use Tests\TestCase;

uses(TestCase::class);

function mcpLines(int $count): string
{
    return implode("\n", array_map(fn (int $i) => "line {$i}", range(1, $count)));
}

it('leaves output under the limit unchanged', function () {
    expect(OutputTail::tail(mcpLines(3), 5))->toBe(mcpLines(3))
        ->and(OutputTail::tail(mcpLines(3)."\n", 3))->toBe(mcpLines(3)."\n")
        ->and(OutputTail::tail('', 5))->toBe('');
});

it('truncates output over the limit with a prefix', function () {
    expect(OutputTail::tail(mcpLines(10), 3))->toBe("[showing last 3 of 10 lines]\nline 8\nline 9\nline 10")
        ->and(OutputTail::tail(mcpLines(10)."\n", 2))->toBe("[showing last 2 of 10 lines]\nline 9\nline 10");
});

it('uses the configured default when no line count is given', function () {
    config(['forge-mcp.log_tail_default' => 4]);

    expect(OutputTail::tail(mcpLines(6), null))->toBe("[showing last 4 of 6 lines]\nline 3\nline 4\nline 5\nline 6");
});

it('clamps the requested line count to the configured maximum', function () {
    config(['forge-mcp.log_tail_max' => 2]);

    expect(OutputTail::tail(mcpLines(6), 500))->toBe("[showing last 2 of 6 lines]\nline 5\nline 6")
        ->and(OutputTail::tail(mcpLines(6), 0))->toBe("[showing last 1 of 6 lines]\nline 6");
});
