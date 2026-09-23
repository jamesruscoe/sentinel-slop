<?php

use App\Scanning\Analysers\PhpStanAnalyser;

test('Eloquent-shaped PHPStan errors are recognised as Laravel misreadings', function (string $identifier, string $message) {
    expect(PhpStanAnalyser::isLaravelMisreading($identifier, $message))->toBeTrue();
})->with([
    'nullsafe on a model property' => ['nullsafe.neverNull', 'Using nullsafe property access "?->name" on left side of ?? is unnecessary. Use -> instead.'],
    'relation return type' => ['return.type', 'Method App\Models\Booking::careLogs() should return Illuminate\Database\Eloquent\Relations\HasMany but returns Illuminate\Database\Query\Builder.'],
    'scope return type' => ['return.type', 'Method App\Models\Message::scopeUnread() should return Illuminate\Database\Eloquent\Builder but returns Illuminate\Database\Query\Builder.'],
    'static create returns Model' => ['return.type', 'Method App\Services\DogService::create() should return App\Models\Dog but returns Illuminate\Database\Eloquent\Model.'],
    'Auth::login given Model' => ['argument.type', 'Parameter #1 $user of static method Illuminate\Support\Facades\Auth::login() expects Illuminate\Contracts\Auth\Authenticatable, Illuminate\Database\Eloquent\Model given.'],
    'closure over an Eloquent collection' => ['argument.unresolvableType', 'Parameter #1 $callback of method Illuminate\Database\Eloquent\Collection<int,App\Models\Company>::map() contains unresolvable type.'],
]);

test('genuine type problems are kept even on Laravel stacks', function (string $identifier, string $message) {
    expect(PhpStanAnalyser::isLaravelMisreading($identifier, $message))->toBeFalse();
})->with([
    'array shape mismatch' => ['return.type', 'Method App\Support\BrandTheme::generateRamp() should return array<string, string> but returns array<int, string>.'],
    'scalar return mismatch' => ['return.type', 'Method App\Models\Order::total() should return int but returns string.'],
    'stale docblock' => ['return.phpDocType', 'PHPDoc tag @return with type array<string, mixed> is incompatible with native type int.'],
    'undefined variable' => ['variable.undefined', 'Undefined variable: $foo'],
    'empty catch' => ['catch.neverThrown', 'Dead catch - Exception is never thrown in the try block.'],
]);
