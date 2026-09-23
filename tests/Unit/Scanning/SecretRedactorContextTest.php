<?php

use App\Scanning\Normalise\SecretRedactor;

test('only a value assigned to a secret-ish key is redacted, never an identifier or member', function (string $input, string $expected) {
    expect(SecretRedactor::scrub($input))->toBe($expected);
})->with([
    'static method call is untouched' => ['Parameter #1 $user of static method Illuminate\Support\Facades\Auth::login() expects Contract, Model given', 'Parameter #1 $user of static method Illuminate\Support\Facades\Auth::login() expects Contract, Model given'],
    'call with args is untouched' => ['Auth::login($user, true);', 'Auth::login($user, true);'],
    'property access is untouched' => ['if ($user->password === null) { return; }', 'if ($user->password === null) { return; }'],
    'config key path is untouched' => ["\$key = config('services.x.token');", "\$key = config('services.x.token');"],
    'method named token is untouched' => ['$request->token()->plainTextToken', '$request->token()->plainTextToken'],
    'json/js style value is redacted' => ['apiKey: "REAL_SECRET_VALUE"', 'apiKey: "[REDACTED]"'],
    'php assignment is redacted' => ["\$apiKey = 'REAL_SECRET_VALUE';", "\$apiKey = '[REDACTED]';"],
    'array pair is redacted' => ["'secret' => 'hunter2hunter2',", "'secret' => '[REDACTED]',"],
    'yaml bare value is redacted' => ['password: hunter2hunter2', 'password: [REDACTED]'],
    'bearer header value is redacted' => ['Authorization: Bearer abc123def456', 'Authorization: Bearer [REDACTED]'],
    'variable on the right is untouched' => ['$this->token = $request->token;', '$this->token = $request->token;'],
    'comparison is untouched' => ['if ($password == $confirm)', 'if ($password == $confirm)'],
    'type hint is untouched' => ['public function __construct(private string $token = null)', 'public function __construct(private string $token = null)'],
]);
