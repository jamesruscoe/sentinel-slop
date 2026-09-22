<?php

use App\Scanning\Normalise\SecretRedactor;

test('secret-ish assignments and known token shapes are redacted', function (string $input, string $expected) {
    expect(SecretRedactor::scrub($input))->toBe($expected);
})->with([
    'assignment by key name' => ['$apiKey = "Qm7xLp2ZtR9vHs4KwN8bYc3JfD6gTe1A";', '$apiKey = "[REDACTED]";'],
    'array key' => ["'secret' => 'hunter2hunter2',", "'secret' => '[REDACTED]',"],
    'yaml style' => ['password: hunter2hunter2', 'password: [REDACTED]'],
    'authorization header' => ['Authorization: Bearer abc123def456', 'Authorization: Bearer [REDACTED]'],
    'stripe-style prefix' => ['charge('.'sk_'.'test_'.'4eC39HqLyjWDarjtT1zdp7dc)', 'charge([REDACTED])'],
    'github-style pat' => ['token '.'gh'.'p_'.str_repeat('Ab1', 12).' used', 'token [REDACTED] used'],
    'aws-style key' => ['Found AWS key '.'AK'.'IA'.'IOSFODNN7EXAMPLE in config', 'Found AWS key [REDACTED] in config'],
    'jwt-style token' => ['ey'.'JhbGciOiJIUzI1NiJ9.'.'eyJzdWIiOiIxMjM0In0.'.'dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U', '[REDACTED]'],
    'mixed case with digits' => ['Found key Qm7xLp2ZtR9vHs4KwN8bYc3JfD6gTe1A here', 'Found key [REDACTED] here'],
    'long hex' => ['hash 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08 ok', 'hash [REDACTED] ok'],
]);

test('identifiers, paths, class names and ordinary strings survive', function (string $input) {
    expect(SecretRedactor::scrub($input))->toBe($input);
})->with([
    'long class name' => 'new OrderShipmentNotification($order)',
    'namespaced class' => 'App\Notifications\OrderShipmentNotificationSender::dispatch()',
    'path literal' => "require 'app/Http/Controllers/ReportController.php';",
    'camel case method' => '$this->calculateOrderTotalWithDiscounts($items)',
    'constant' => 'MAX_CONCURRENT_BACKGROUND_WORKERS_PER_QUEUE',
    'url path with version' => 'fetch("/api/v2/orders/12345/items")',
    'message with numbers' => 'Method App\Models\Order::total() should return int but returns string.',
    'sha-like short id' => 'commit 1513f6f on main',
]);

test('known values are always removed and quoted secrets in prose are handled', function () {
    expect(SecretRedactor::redactValue('token=ghp_abc123 ok', 'ghp_abc123'))->toBe('token=[REDACTED] ok')
        ->and(SecretRedactor::looksLikeSecret('OrderShipmentNotification'))->toBeFalse()
        ->and(SecretRedactor::looksLikeSecret('Qm7xLp2ZtR9vHs4KwN8bYc3JfD6gTe1A'))->toBeTrue();
});
