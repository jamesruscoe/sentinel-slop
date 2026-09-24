<?php

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use App\Scanning\Profile\AbsenceChecks;
use App\Scanning\Profile\Naming;
use App\Scanning\Profile\ProfileConfig;
use App\Scanning\Profile\RepositoryProfiler;

function profileFixture(string $fixture, array $frameworks = [], ?FindingCollection $jscpd = null): array
{
    $profile = (new RepositoryProfiler)->profile(fixturePath($fixture), new Stack(frameworks: $frameworks), $jscpd);

    return [$profile, (new AbsenceChecks)->run($profile)];
}

function profileDirectory(string $path, array $frameworks = [], ?ProfileConfig $config = null): array
{
    $config ??= new ProfileConfig;
    $profile = (new RepositoryProfiler($config))->profile($path, new Stack(frameworks: $frameworks));

    return [$profile, (new AbsenceChecks($config))->run($profile)];
}

function ruleIds(FindingCollection $findings): array
{
    $ids = array_map(fn (Finding $f) => (string) $f->ruleId, $findings->all());
    sort($ids);

    return $ids;
}

test('a well-structured laravel app produces no absence findings', function () {
    [$profile, $findings] = profileFixture('well-structured', ['laravel']);
    $logging = $profile->section('logging');
    $validation = $profile->section('validation');
    $services = collect($profile->section('areas'))->firstWhere('area', 'app/Services');

    expect(ruleIds($findings))->toBe([])
        ->and($logging['files_logging'])->toBeGreaterThanOrEqual(9)
        ->and($logging['via_wrapper'])->toBeGreaterThanOrEqual(8)
        ->and($services['logging_files'])->toBe(8)
        ->and($services['service_like'])->toBeTrue()
        ->and($services['linked_tests'])->toBeGreaterThan(0)
        ->and($validation['form_request_classes'])->toBe(3)
        ->and($profile->section('config')['env_sites_outside_config'])->toBe(0)
        ->and($profile->section('tests')['test_files'])->toBe(5)
        ->and($profile->section('documentation')['readme'])->toBeTrue();
});

test('a framework-less python package with nothing in place produces exactly the expected absence findings', function () {
    [$profile, $findings] = profileFixture('python-service');

    // Handlers, jobs and five repositories are imported by nothing: nothing in the package wires them up (one finding, one area).
    expect(ruleIds($findings))->toBe(['env-read-outside-config', 'no-input-validation', 'no-logging-anywhere', 'no-tests-at-all', 'unguarded-external-calls', 'unreferenced-code'])
        // 20: the shipment repository is referenced only by the dead jobs, so the fixed point marks it dead as well.
        ->and(count($profile->section('reachability')['unreferenced']))->toBe(20);

    $bySeverity = [];
    foreach ($findings as $finding) {
        $bySeverity[(string) $finding->ruleId] = $finding->severity;
        expect($finding->category)->toBe(FindingCategory::Structure)->and($finding->tool)->toBe('profile');
    }

    expect($bySeverity['no-tests-at-all'])->toBe(Severity::High)
        ->and($bySeverity['no-input-validation'])->toBe(Severity::High)
        ->and($bySeverity['env-read-outside-config'])->toBe(Severity::Medium)
        ->and($profile->section('summary')['assessed_families'])->toBe(['python'])
        ->and($profile->section('errors')['external_sites'])->toBeGreaterThanOrEqual(12)
        ->and($profile->section('config')['env_sites_outside_config'])->toBeGreaterThanOrEqual(8);

    $messages = implode("\n", array_map(fn (Finding $f) => $f->message, $findings->all()));
    expect($messages)->toContain('shipping/handlers/ups_handler.py')->toContain('no test framework is declared');
});

test('naming conventions resolve areas, kinds and feature stems', function () {
    expect(Naming::areaOf('app/Http/Controllers/DogController.php'))->toBe('app/Http/Controllers')
        ->and(Naming::areaOf('resources/js/pages/Staff/Dogs/Index.vue'))->toBe('resources/js/pages')
        ->and(Naming::areaOf('resources/js/app.ts'))->toBe('resources/js')
        ->and(Naming::areaOf('routes/web.php'))->toBe('routes')
        ->and(Naming::areaOf('README.md'))->toBe('(root)')
        ->and(Naming::areaOf('src/shipping/handlers/ups.py'))->toBe('src/shipping')
        ->and(Naming::kindOf('app/Http/Controllers/DogController.php'))->toBe('controller')
        ->and(Naming::kindOf('app/Http/Requests/StoreDogRequest.php'))->toBe('request')
        ->and(Naming::kindOf('app/Http/Resources/DogResource.php'))->toBe('resource')
        ->and(Naming::kindOf('resources/js/app.ts'))->toBe('code')
        ->and(Naming::kindOf('resources/js/pages/Staff/Dogs/Index.vue'))->toBe('page')
        ->and(Naming::kindOf('resources/js/stores/useBookingStore.ts'))->toBe('store')
        ->and(Naming::kindOf('resources/views/welcome.blade.php'))->toBe('view')
        ->and(Naming::kindOf('database/migrations/2024_01_01_000000_create_dogs_table.php'))->toBe('migration')
        ->and(Naming::kindOf('tests/Feature/DogTest.php'))->toBe('test')
        ->and(Naming::stemOf('app/Http/Controllers/DogController.php'))->toBe('dog')
        ->and(Naming::stemOf('app/Http/Requests/StoreDogRequest.php'))->toBe('dog')
        ->and(Naming::stemOf('resources/js/pages/Staff/Dogs/Index.vue'))->toBe('dog')
        ->and(Naming::stemOf('resources/js/stores/useBookingStore.ts'))->toBe('booking')
        ->and(Naming::stemOf('app/Events/BookingApproved.php'))->toBe('booking')
        ->and(Naming::stemOf('app/Services/CapacityService.php'))->toBe('capacity')
        ->and(Naming::stemOf('resources/js/types/kennel.ts'))->toBe('kennel')
        ->and(Naming::isTestName('tests/Feature/DogTest.php'))->toBeTrue()
        ->and(Naming::isTestName('resources/js/components/Dog.spec.ts'))->toBeTrue()
        ->and(Naming::isTestName('shipping/handlers/test_ups.py'))->toBeTrue()
        ->and(Naming::isTestName('app/Services/DogService.php'))->toBeFalse()
        ->and(Naming::isConfigPath('config/services.php'))->toBeTrue()
        ->and(Naming::isConfigPath('vite.config.ts'))->toBeTrue()
        ->and(Naming::isConfigPath('app/Services/DogService.php'))->toBeFalse();
});

test('logging is recognised through an aliased facade and through an injected wrapper that is not named like a logger', function () {
    $workspace = temporaryWorkspace();
    $repo = $workspace->repoPath();
    file_put_contents($repo.'/composer.json', '{"require":{"laravel/framework":"^12.0"},"autoload":{"psr-4":{"App\\\\":"app/"}}}');
    @mkdir($repo.'/app/Support', 0777, true);
    @mkdir($repo.'/app/Services', 0777, true);
    @mkdir($repo.'/app/Http/Controllers', 0777, true);
    file_put_contents($repo.'/app/Support/Trail.php', "<?php\n\nnamespace App\\Support;\n\nuse Psr\\Log\\LoggerInterface;\n\nfinal class Trail\n{\n    public function __construct(private LoggerInterface \$l) {}\n\n    public function note(string \$m): void\n    {\n        \$this->l->info(\$m);\n    }\n}\n");
    file_put_contents($repo.'/app/Services/AliasService.php', "<?php\n\nnamespace App\\Services;\n\nuse Illuminate\\Support\\Facades\\Log as AppLog;\n\nfinal class AliasService\n{\n    public function run(): void\n    {\n        AppLog::info('ran');\n    }\n}\n");
    for ($i = 1; $i <= 7; $i++) {
        file_put_contents($repo."/app/Services/Wrapped{$i}Service.php", "<?php\n\nnamespace App\\Services;\n\nuse App\\Support\\Trail;\n\nfinal class Wrapped{$i}Service\n{\n    public function __construct(private Trail \$trail) {}\n\n    public function run(): void\n    {\n        \$this->trail->note('ran {$i}');\n    }\n}\n");
    }
    file_put_contents($repo.'/app/Http/Controllers/HomeController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nfinal class HomeController\n{\n    public function index(): string\n    {\n        return 'home';\n    }\n}\n");

    [$profile, $findings] = profileDirectory($repo, ['laravel']);
    $services = collect($profile->section('areas'))->firstWhere('area', 'app/Services');

    expect(ruleIds($findings))->not->toContain('no-logging-in-layer', 'no-logging-anywhere')
        ->and($services['logging_files'])->toBe(8)
        ->and($services['logging_via_wrapper'])->toBe(7)
        ->and($profile->section('logging')['by_family']['php']['mechanisms'])->toContain('Illuminate\Support\Facades\Log', 'Psr\Log\LoggerInterface')
        ->and($profile->section('logging')['wrapper_files'])->toBe(['app/Support/Trail.php']);
});

test('a model named like a log is not mistaken for a logger', function () {
    $workspace = temporaryWorkspace();
    $repo = $workspace->repoPath();
    file_put_contents($repo.'/composer.json', '{"require":{"laravel/framework":"^12.0"},"autoload":{"psr-4":{"App\\\\":"app/"}}}');
    @mkdir($repo.'/app/Models', 0777, true);
    @mkdir($repo.'/app/Services', 0777, true);
    file_put_contents($repo.'/app/Models/CareLog.php', "<?php\n\nnamespace App\\Models;\n\nclass CareLog\n{\n}\n");
    file_put_contents($repo.'/app/Services/CareService.php', "<?php\n\nnamespace App\\Services;\n\nuse App\\Models\\CareLog;\n\nfinal class CareService\n{\n    public function latest(): ?CareLog\n    {\n        return null;\n    }\n}\n");

    [$profile] = profileDirectory($repo, ['laravel']);

    expect($profile->section('logging')['files_logging'])->toBe(0);
});

test('jscpd pairs become duplication clusters and their pair findings are superseded', function () {
    $pairs = new FindingCollection([
        new Finding('jscpd', 'duplicate-block', FindingCategory::Duplication, Severity::Low, 'app/B.php', 10, '25 duplicated lines also found in app/A.php:5.'),
        new Finding('jscpd', 'duplicate-block', FindingCategory::Duplication, Severity::Low, 'app/C.php', 40, '25 duplicated lines also found in app/A.php:5.'),
        new Finding('jscpd', 'duplicate-block', FindingCategory::Duplication, Severity::Low, 'app/E.php', 1, '12 duplicated lines also found in app/D.php:1.'),
    ]);

    [$profile, $findings] = profileFixture('sloppy-laravel', ['laravel'], $pairs);
    $duplication = $profile->section('duplication');
    $cluster = $findings->filter(fn (Finding $f) => $f->ruleId === 'duplication-cluster');

    expect($duplication['clusters'][0]['occurrences'])->toBe(3)
        ->and($duplication['clusters'][0]['lines_saved'])->toBe(50)
        ->and($duplication['clusters'][0]['locations'])->toBe(['app/A.php:5', 'app/B.php:10', 'app/C.php:40'])
        ->and($duplication['superseded_pairs'])->toBe(['app/B.php:10', 'app/C.php:40'])
        ->and($cluster->count())->toBe(1)
        ->and($cluster->all()[0]->severity)->toBe(Severity::Medium)
        ->and($cluster->all()[0]->message)->toContain('appears 3 times in 3 files')->toContain('50 lines');
});

test('a flat directory mixing many kinds with unrelated names is a dumping ground, a directory of one kind is not', function () {
    $workspace = temporaryWorkspace();
    $repo = $workspace->repoPath();
    @mkdir($repo.'/lib', 0777, true);
    @mkdir($repo.'/app/Models', 0777, true);
    $names = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliet'];
    $unrelated = ['Anchor', 'Basket', 'Candle', 'Dagger', 'Ember', 'Falcon', 'Garden', 'Hammer', 'Island', 'Jigsaw', 'Kettle', 'Ladder', 'Marble', 'Needle', 'Orbit', 'Pepper', 'Quiver', 'Ribbon', 'Saddle', 'Timber', 'Umbrella', 'Velvet', 'Walnut', 'Xylophone', 'Yonder', 'Zipper', 'Acorn', 'Beacon', 'Cobalt', 'Dune'];
    $suffixes = ['Helper', 'Service', 'Controller'];
    foreach ($unrelated as $i => $name) {
        $suffix = $suffixes[$i % 3];
        file_put_contents($repo."/lib/{$name}{$suffix}.php", "<?php\n\nclass {$name}{$suffix}\n{\n}\n");
    }
    foreach ($names as $name) {
        foreach (['One', 'Two', 'Three'] as $n) {
            file_put_contents($repo."/app/Models/{$name}{$n}.php", "<?php\n\nnamespace App\\Models;\n\nclass {$name}{$n}\n{\n}\n");
        }
    }

    [, $findings] = profileDirectory($repo);
    $dumping = $findings->filter(fn (Finding $f) => $f->ruleId === 'dumping-ground-directory');

    expect($dumping->count())->toBe(1)
        ->and($dumping->all()[0]->filePath)->toBe('lib')
        ->and($dumping->all()[0]->message)->toContain('30 files directly')->toContain('3 kinds');
});

test('files nothing imports, mentions or globs are unreferenced; framework entry points and page directories are not', function () {
    $workspace = temporaryWorkspace();
    $repo = $workspace->repoPath();
    file_put_contents($repo.'/composer.json', '{"require":{"laravel/framework":"^12.0"},"autoload":{"psr-4":{"App\\\\":"app/"},"files":["app/helpers.php"]}}');
    foreach (['app/Http/Controllers', 'app/Services', 'bootstrap', 'routes', 'resources/js/pages/Dogs', 'resources/js/services', 'resources/js/Components', 'resources/views/emails'] as $dir) {
        @mkdir($repo.'/'.$dir, 0777, true);
    }
    file_put_contents($repo.'/bootstrap/app.php', "<?php\n\nreturn Application::configure(basePath: dirname(__DIR__))->withRouting(web: __DIR__.'/../routes/web.php')->create();\n");
    file_put_contents($repo.'/routes/web.php', "<?php\n\nuse App\\Http\\Controllers\\DogController;\n\nRoute::resource('dogs', DogController::class);\n");
    file_put_contents($repo.'/routes/kennel.php', "<?php\n\nRoute::get('/kennel', fn () => 'never loaded');\n");
    file_put_contents($repo.'/app/helpers.php', "<?php\n\nfunction kennel_name(): string\n{\n    return 'Kennel';\n}\n");
    file_put_contents($repo.'/app/Http/Controllers/DogController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\DogService;\nuse Inertia\\Inertia;\n\nclass DogController\n{\n    public function index(DogService \$dogs)\n    {\n        return Inertia::render('Dogs/Index', ['dogs' => \$dogs->all()]);\n    }\n}\n");
    file_put_contents($repo.'/app/Http/Controllers/HandleAccountController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\AccountService;\nuse App\\Services\\OrphanService;\n\nclass HandleAccountController\n{\n    public function __invoke(AccountService \$accounts, OrphanService \$orphans): void\n    {\n        \$accounts->handle();\n    }\n}\n");
    // Referenced only by a dead controller: dead too (fixed point), like a controller wired only from an unloaded route file.
    file_put_contents($repo.'/app/Services/OrphanService.php', "<?php\n\nnamespace App\\Services;\n\nclass OrphanService\n{\n    public function handle(): void\n    {\n    }\n}\n");
    file_put_contents($repo.'/app/Services/DogService.php', "<?php\n\nnamespace App\\Services;\n\nclass DogService\n{\n    public function all(): array\n    {\n        return view('emails.welcome') ? [] : [];\n    }\n}\n");
    file_put_contents($repo.'/resources/views/emails/welcome.blade.php', "<p>Welcome</p>\n");
    file_put_contents($repo.'/resources/js/app.ts', "import { createInertiaApp } from '@inertiajs/vue3'\nconst pages = import.meta.glob('./pages/**/*.vue')\ncreateInertiaApp({ resolve: (name) => pages[`./pages/\${name}.vue`] })\n");
    file_put_contents($repo.'/resources/js/pages/Dogs/Index.vue', "<script setup lang=\"ts\">\nimport { fetchDogs } from '@/services/DogApi'\nimport Card from '@/Components/Card.vue'\n</script>\n<template><Card /></template>\n");
    file_put_contents($repo.'/resources/js/pages/Orphan.vue', "<template><div>nobody renders me, but pages are globbed</div></template>\n");
    file_put_contents($repo.'/resources/js/services/DogApi.ts', "export const fetchDogs = () => fetch('/api/dogs')\n");
    file_put_contents($repo.'/resources/js/services/NotificationApiService.ts', "export const fetchNotifications = () => fetch('/api/notifications')\n");
    file_put_contents($repo.'/resources/js/Components/Card.vue', "<template><div class=\"card\"><slot /></div></template>\n");
    file_put_contents($repo.'/resources/js/Components/Unused.vue', "<template><div>unused</div></template>\n");

    [$profile, $findings] = profileDirectory($repo, ['laravel']);
    $unreferenced = array_map(fn (array $f) => $f['path'], $profile->section('reachability')['unreferenced']);
    sort($unreferenced);
    $byRule = [];
    foreach ($findings as $finding) {
        $byRule[(string) $finding->ruleId][] = $finding;
    }

    expect($unreferenced)->toBe(['app/Http/Controllers/HandleAccountController.php', 'app/Services/OrphanService.php', 'resources/js/Components/Unused.vue', 'resources/js/services/NotificationApiService.ts', 'routes/kennel.php'])
        ->and($profile->section('reachability')['missing_own_classes'])->toBe([['file' => 'app/Http/Controllers/HandleAccountController.php', 'class' => 'App\Services\AccountService']])
        ->and(array_map(fn (Finding $f) => $f->filePath, $byRule['unreferenced-code']))->toBe(['app/Http/Controllers', 'app/Services', 'resources/js/Components', 'resources/js/services', 'routes'])
        ->and($byRule['missing-own-class'][0]->severity)->toBe(Severity::Medium)
        ->and($byRule['missing-own-class'][0]->message)->toContain('App\Services\AccountService', 'delete both');
});

test('a package wired by package discovery, a driver name or a config token is never called unreferenced', function () {
    $workspace = temporaryWorkspace();
    $repo = $workspace->repoPath();
    @mkdir($repo.'/config', 0777, true);
    @mkdir($repo.'/app', 0777, true);
    file_put_contents($repo.'/composer.json', json_encode(['require' => ['laravel/framework' => '^12.0', 'league/flysystem-aws-s3-v3' => '^3.0', 'pbmedia/laravel-ffmpeg' => '^8.0', 'acme/discovered' => '^1.0', 'laravel/helpers' => '^1.0', 'acme/forgotten' => '^1.0'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    file_put_contents($repo.'/composer.lock', json_encode(['_readme' => ['This file is @generated automatically'], 'packages' => [
        ['name' => 'laravel/framework', 'version' => 'v12.0.0', 'autoload' => ['psr-4' => ['Illuminate\\' => 'src/Illuminate/']]],
        ['name' => 'league/flysystem-aws-s3-v3', 'version' => '3.0.0', 'autoload' => ['psr-4' => ['League\\Flysystem\\AwsS3V3\\' => '']]],
        ['name' => 'pbmedia/laravel-ffmpeg', 'version' => '8.0.0', 'autoload' => ['psr-4' => ['ProtoneMedia\\LaravelFFMpeg\\' => 'src/']]],
        ['name' => 'acme/discovered', 'version' => '1.0.0', 'autoload' => ['psr-4' => ['Acme\\Discovered\\' => 'src/']], 'extra' => ['laravel' => ['providers' => ['Acme\\Discovered\\ServiceProvider']]]],
        ['name' => 'laravel/helpers', 'version' => '1.0.0', 'autoload' => ['files' => ['src/helpers.php']]],
        ['name' => 'acme/forgotten', 'version' => '1.0.0', 'autoload' => ['psr-4' => ['Acme\\Forgotten\\' => 'src/']]],
    ], 'packages-dev' => []]));
    file_put_contents($repo.'/config/filesystems.php', "<?php\n\nreturn ['disks' => ['s3' => ['driver' => 's3', 'key' => env('AWS_ACCESS_KEY_ID')]]];\n");
    file_put_contents($repo.'/.env.example', "MEDIA_DISK=s3\nFFMPEG_BINARIES=/usr/bin/ffmpeg\n");
    file_put_contents($repo.'/app/Thing.php', "<?php\n\nnamespace App;\n\nuse Illuminate\\Support\\Str;\n\nclass Thing\n{\n    public function name(): string\n    {\n        return Str::slug('x');\n    }\n}\n");

    [$profile] = profileDirectory($repo, ['laravel']);
    $dependencies = $profile->section('dependencies');

    expect($dependencies['never_referenced'])->toBe(['composer:acme/forgotten'])
        ->and(implode("\n", $dependencies['wired_without_import']))->toContain('league/flysystem-aws-s3-v3', 'pbmedia/laravel-ffmpeg', 'acme/discovered', 'laravel/helpers')
        ->and(implode("\n", $dependencies['wired_without_import']))->toContain("'ffmpeg' appears in config or .env.example", 'package discovery');
});

test('no absence finding fires below its minimum population', function () {
    $workspace = temporaryWorkspace();
    $repo = $workspace->repoPath();
    @mkdir($repo.'/app/Services', 0777, true);
    @mkdir($repo.'/app/Http/Controllers', 0777, true);
    @mkdir($repo.'/routes', 0777, true);
    file_put_contents($repo.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
    for ($i = 1; $i <= 3; $i++) {
        file_put_contents($repo."/app/Services/Thing{$i}Service.php", "<?php\n\nnamespace App\\Services;\n\nfinal class Thing{$i}Service\n{\n    public function run(): int\n    {\n        return getenv('THING') ? 1 : 0;\n    }\n}\n");
    }
    file_put_contents($repo.'/app/Http/Controllers/ThingController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Thing1Service;\nuse App\\Services\\Thing2Service;\nuse App\\Services\\Thing3Service;\n\nfinal class ThingController\n{\n    public function store(\$request, Thing1Service \$a, Thing2Service \$b, Thing3Service \$c): void\n    {\n        \$request->input('name');\n    }\n}\n");
    file_put_contents($repo.'/routes/web.php', "<?php\n\nuse App\\Http\\Controllers\\ThingController;\n\nRoute::post('/things', ThingController::class);\n");

    [, $findings] = profileDirectory($repo, ['laravel']);

    expect(ruleIds($findings))->toBe([]);
});
