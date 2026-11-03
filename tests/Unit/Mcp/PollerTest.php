<?php

use App\Mcp\Support\Poller;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);

it('returns immediately when the first fetch is done', function () {
    Sleep::fake();

    $out = Poller::until(fn () => 'ok', fn ($r) => $r === 'ok');

    expect($out)->toBe(['result' => 'ok', 'done' => true]);
    Sleep::assertNeverSlept();
});

it('stops early once done', function () {
    Sleep::fake();
    $calls = 0;

    $out = Poller::until(function () use (&$calls) {
        return ++$calls;
    }, fn ($r) => $r === 3);

    expect($out)->toBe(['result' => 3, 'done' => true]);
    Sleep::assertSleptTimes(2);
});

it('gives up at the timeout with done=false', function () {
    Sleep::fake();

    $out = Poller::until(fn () => 'running', fn () => false, 20);

    expect($out)->toBe(['result' => 'running', 'done' => false]);
});

it('sleeps 1, 2, 3, 5, 5 seconds and never beyond the timeout', function () {
    Sleep::fake();

    Poller::until(fn () => null, fn () => false, 20);

    Sleep::assertSequence([
        Sleep::for(1)->seconds(),
        Sleep::for(2)->seconds(),
        Sleep::for(3)->seconds(),
        Sleep::for(5)->seconds(),
        Sleep::for(5)->seconds(),
        Sleep::for(4)->seconds(),
    ]);
});

it('defaults to the configured poll timeout', function () {
    Sleep::fake();
    config(['forge-mcp.poll_timeout' => 3]);

    Poller::until(fn () => null, fn () => false);

    Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(2)->seconds()]);
});
