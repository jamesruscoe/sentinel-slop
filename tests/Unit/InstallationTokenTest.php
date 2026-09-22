<?php

use App\Services\GitHub\InstallationToken;
use Carbon\CarbonImmutable;

test('an installation token cannot be serialised or dumped', function () {
    $token = new InstallationToken('ghs_secret', CarbonImmutable::now()->addHour());

    expect($token->value())->toBe('ghs_secret')
        ->and($token->isExpired())->toBeFalse()
        ->and(print_r($token, true))->not->toContain('ghs_secret')
        ->and(fn () => serialize($token))->toThrow(LogicException::class);
});
