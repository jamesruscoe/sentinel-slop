<?php

use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\ProcessSpec;
use App\Scanning\Exceptions\AnalyserUnavailableException;
use App\Scanning\Process\ToolLocator;

test('analyser processes never inherit app secrets but keep what they need to start', function () {
    putenv('SENTINEL_TEST_SECRET=super-secret');
    $_ENV['SENTINEL_TEST_SECRET'] = 'super-secret';

    $result = app(ProcessRunner::class)->run(new ProcessSpec(
        [PHP_BINARY, '-r', 'echo json_encode(["secret" => getenv("SENTINEL_TEST_SECRET"), "app_key" => getenv("APP_KEY"), "path" => getenv("PATH") !== false, "extra" => getenv("SENTINEL_EXTRA")]);'],
        base_path(),
        30,
        ['SENTINEL_EXTRA' => 'yes'],
    ));

    putenv('SENTINEL_TEST_SECRET');
    unset($_ENV['SENTINEL_TEST_SECRET']);

    expect($result->successful())->toBeTrue()
        ->and(json_decode($result->stdout, true))->toBe(['secret' => false, 'app_key' => false, 'path' => true, 'extra' => 'yes']);
});

test('processes are killed at the timeout', function () {
    $result = app(ProcessRunner::class)->run(new ProcessSpec([PHP_BINARY, '-r', 'sleep(10);'], base_path(), 1));

    expect($result->timedOut)->toBeTrue()->and($result->successful())->toBeFalse();
});

test('the tool locator resolves every bundled and system tool on this machine', function () {
    $report = app(ToolLocator::class)->report();

    foreach (ToolLocator::TOOLS as $tool) {
        expect($report[$tool]['available'])->toBeTrue("{$tool}: ".($report[$tool]['error'] ?? ''));
    }
});

test('the tool locator explains what is missing', function () {
    $locator = new ToolLocator(['php' => PHP_BINARY, 'phpstan' => '/nowhere/phpstan', 'semgrep' => 'definitely-not-a-binary']);

    expect(fn () => $locator->command('phpstan'))->toThrow(AnalyserUnavailableException::class, 'SENTINEL_PHPSTAN_PATH')
        ->and(fn () => $locator->command('semgrep'))->toThrow(AnalyserUnavailableException::class, 'SENTINEL_SEMGREP_BINARY')
        ->and(fn () => $locator->command('wat'))->toThrow(AnalyserUnavailableException::class)
        ->and($locator->isAvailable('phpstan'))->toBeFalse();
});

test('sentinel:doctor reports every tool', function () {
    $this->artisan('sentinel:doctor')->assertSuccessful();
});
