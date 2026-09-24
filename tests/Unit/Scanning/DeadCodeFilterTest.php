<?php

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use App\Scanning\Normalise\DeadCodeFilter;

function deadFinding(string $tool, string $rule, string $file, string $message = 'm'): Finding
{
    return new Finding($tool, $rule, FindingCategory::Other, Severity::Medium, $file, 1, $message);
}

test('findings inside unreferenced files are dropped, the profile findings that name them are kept', function () {
    $findings = new FindingCollection([
        deadFinding('phpstan', 'return.type', 'app/Http/Controllers/HandleAccountController.php'),
        deadFinding('placeholder-code', 'unimplemented-method', 'app/Services/CapacityService.php'),
        deadFinding('profile', 'unreferenced-code', 'app/Http/Controllers'),
        deadFinding('profile', 'missing-own-class', 'app/Http/Controllers/HandleAccountController.php'),
        deadFinding('jscpd', 'duplicate-block', 'routes/tenant.php', '27 duplicated lines (12 statements) also found in routes/kennel.php:24.'),
        deadFinding('jscpd', 'duplicate-block', 'routes/tenant.php', '20 duplicated lines (9 statements) also found in routes/web.php:4.'),
        deadFinding('pint', 'style', 'app/Services/BookingService.php'),
        // A secret in a dead file is still a secret: sloppy-laravel's planted key vanished with its dead controller.
        new Finding('gitleaks', 'generic-api-key', FindingCategory::Secrets, Severity::Critical, 'app/Http/Controllers/HandleAccountController.php', 9, 'Possible secret'),
    ]);

    $kept = (new DeadCodeFilter(['app/Http/Controllers/HandleAccountController.php', 'routes/kennel.php']))->filter($findings);
    $keys = array_map(fn (Finding $f) => $f->tool.'|'.$f->filePath.'|'.$f->message, $kept->all());

    expect($keys)->toBe([
        'placeholder-code|app/Services/CapacityService.php|m',
        'profile|app/Http/Controllers|m',
        'profile|app/Http/Controllers/HandleAccountController.php|m',
        'jscpd|routes/tenant.php|20 duplicated lines (9 statements) also found in routes/web.php:4.',
        'pint|app/Services/BookingService.php|m',
        'gitleaks|app/Http/Controllers/HandleAccountController.php|Possible secret',
    ]);
});

test('with nothing unreferenced the collection passes through untouched', function () {
    $findings = new FindingCollection([deadFinding('phpstan', 'return.type', 'app/A.php')]);

    expect((new DeadCodeFilter([]))->filter($findings))->toBe($findings);
});
