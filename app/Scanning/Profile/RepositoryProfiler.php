<?php

declare(strict_types=1);

namespace App\Scanning\Profile;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Detect\DependencyIndex;

/**
 * Computes the RepositoryProfile from the file tree alone (plus jscpd's
 * pair findings for duplication clusters). No analyser, no execution, no
 * framework. See RepositoryProfile for the sections.
 *
 * @phpstan-import-type FileFact from SourceInventory
 *
 * @phpstan-type Manifests array{composer: array<string, true>, composer_dev: array<string, true>, composer_all: array<string, true>, composer_files: list<string>, npm: array<string, true>, npm_dev: array<string, true>, npm_all: array<string, true>, python: list<string>}
 */
final class RepositoryProfiler
{
    private const TEST_FRAMEWORKS = ['phpunit', 'pest', 'jest', 'vitest', 'mocha', 'jasmine', 'cypress', 'playwright', 'pytest', 'rspec', 'minitest', 'junit', 'xunit', 'nunit', 'karma', 'ava', 'tap', 'unittest'];

    /** Runtime packages that are tooling and never imported from code. */
    private const TOOLING_PACKAGES = ['laravel/tinker', 'laravel/ui', 'laravel/sail', 'laravel/pail', 'laravel/pint', 'laravel/boost', 'laravel/breeze', 'laravel/telescope', 'laravel/horizon', 'laravel/pulse', 'laravel/octane', 'laravel/envoy', 'laravel/dusk', 'barryvdh/laravel-debugbar', 'barryvdh/laravel-ide-helper', 'nunomaduro/collision', 'spatie/laravel-ignition', 'filp/whoops'];

    public function __construct(private readonly ProfileConfig $config = new ProfileConfig) {}

    public function profile(string $repoPath, Stack $stack, ?FindingCollection $jscpd = null, ?FindingCollection $style = null): RepositoryProfile
    {
        $inventory = SourceInventory::build($repoPath);
        $all = $inventory->files;
        $source = $inventory->source();
        $graph = ImportGraph::build($repoPath, $all);
        $index = DependencyIndex::build($repoPath);
        $manifests = $this->manifests($repoPath);
        $reachability = $this->reachability($all, $graph, $manifests);
        // Duplication in a dead file is not duplication to extract: drop those pairs before clustering.
        $dead = array_fill_keys(array_column($reachability['unreferenced'], 'path'), true);
        if ($jscpd !== null && $dead !== []) {
            $jscpd = $jscpd->filter(fn (Finding $f) => ! isset($dead[$f->filePath]) && ! (preg_match('/also found in (.+):\d+\.$/', $f->message, $m) === 1 && isset($dead[$m[1]])));
        }

        $logging = $this->logging($source, $graph, $manifests);
        $validation = $this->validation($source, $graph, $manifests);
        $errors = $this->errors($source, $stack);
        $config = $this->config($source);
        $tests = $this->tests($source, $all, $graph, $stack, $manifests);
        $areas = $this->areas($all, $source, $logging['by_file'], $tests['linked']);
        $features = $this->features($all, $graph);
        $duplication = $this->duplication($jscpd);
        $dependencies = $this->dependencies($source, $index, $manifests, $repoPath);
        $cohesion = $this->cohesion($all);
        $documentation = $this->documentation($all, $areas);
        $summary = $this->summary($all, $source, $stack);

        $data = [
            'summary' => $summary,
            'areas' => $areas,
            'largest_files' => $this->largestFiles($source),
            'largest_functions' => $this->largestFunctions($source),
            'features' => $features,
            'duplication' => $duplication,
            'tests' => array_diff_key($tests, ['linked' => true]),
            'logging' => array_diff_key($logging, ['by_file' => true]),
            'validation' => $validation,
            'errors' => $errors,
            'config' => $config,
            'dependencies' => $dependencies,
            'cohesion' => $cohesion,
            'documentation' => $documentation,
            'reachability' => $reachability,
            'style' => $this->style($repoPath, $source, $style),
        ];
        $data['observations'] = $this->observations($data);

        return new RepositoryProfile($data);
    }

    /**
     * @param  list<FileFact>  $all
     * @param  list<FileFact>  $source
     * @return array<string, mixed>
     */
    private function summary(array $all, array $source, Stack $stack): array
    {
        $families = [];
        $tests = 0;
        $codeLines = 0;
        $commentLines = 0;
        $depth = 0;
        foreach ($source as $file) {
            $families[(string) $file['family']] = ($families[(string) $file['family']] ?? 0) + 1;
            if ($file['is_test']) {
                $tests++;
            }
            $codeLines += $file['code_lines'];
            $commentLines += $file['comment_lines'];
            $depth = max($depth, $file['depth']);
        }
        arsort($families);

        return [
            'files' => count($all),
            'source_files' => count($source) - $tests,
            'test_files' => $tests,
            'code_lines' => $codeLines,
            'comment_lines' => $commentLines,
            'comment_density' => $codeLines > 0 ? round($commentLines / $codeLines * 100, 1) : 0.0,
            'families' => $families,
            'assessed_families' => array_values(array_filter(array_keys($families), fn (string $f) => LanguagePatterns::isAssessed($f))),
            'unassessed_families' => array_values(array_filter(array_keys($families), fn (string $f) => ! LanguagePatterns::isAssessed($f))),
            'max_depth' => $depth,
            'frameworks' => $stack->frameworks,
        ];
    }

    /**
     * @param  list<FileFact>  $source
     * @param  Manifests  $manifests
     * @return array{by_file: array<string, string>, files_logging: int, direct: int, via_wrapper: int, mechanisms: list<string>, wrapper_files: list<string>, by_family: array<string, array{files_logging: int, direct: int, via_wrapper: int, source_files: int, mechanisms: list<string>}>}
     */
    private function logging(array $source, ImportGraph $graph, array $manifests): array
    {
        $direct = [];
        $mechanisms = [];
        $byPath = [];
        foreach ($source as $file) {
            $byPath[$file['path']] = $file;
        }

        foreach ($source as $file) {
            $family = (string) $file['family'];
            if ($file['signals']['logging_call'] > 0) {
                $direct[$file['path']] = true;
                $mechanisms[$family][$family === 'js' ? 'console/logger calls' : 'logging calls'] = true;
            }
            foreach ($family === 'php' ? $file['references'] : $file['imports'] as $reference) {
                if (self::isLoggerReference($family, $reference)) {
                    $direct[$file['path']] = true;
                    $mechanisms[$family][self::loggerLabel($family, $reference)] = true;
                }
            }
        }

        // A file that does not log itself still logs when it imports (within wrapper_hops) a non-UI
        // repository file that does: a helper, service or module wrapping the logger. Importing a page
        // or component that happens to call console.error is not that.
        $byFile = [];
        $reachCounts = [];
        /** @var array<string, array{files_logging: int, direct: int, via_wrapper: int, source_files: int, mechanisms: list<string>}> $byFamily */
        $byFamily = [];
        foreach ($source as $file) {
            $family = (string) $file['family'];
            $counts = $byFamily[$family] ?? ['files_logging' => 0, 'direct' => 0, 'via_wrapper' => 0, 'source_files' => 0, 'mechanisms' => []];
            $counts['source_files']++;
            if (isset($direct[$file['path']])) {
                $byFile[$file['path']] = 'direct';
                $counts['direct']++;
                $counts['files_logging']++;
            } else {
                foreach ($graph->reachable($file['path'], $this->config->wrapperHops) as $reached) {
                    if (isset($direct[$reached]) && ! in_array($byPath[$reached]['kind'] ?? 'code', Naming::UI_KINDS, true)) {
                        $byFile[$file['path']] = 'wrapper:'.$reached;
                        $reachCounts[$reached] = ($reachCounts[$reached] ?? 0) + 1;
                        $counts['via_wrapper']++;
                        $counts['files_logging']++;

                        break;
                    }
                }
            }
            $byFamily[$family] = $counts;
        }
        arsort($reachCounts);
        $viaWrapper = 0;
        $labels = [];
        foreach ($byFamily as $family => $counts) {
            $byFamily[$family]['mechanisms'] = array_keys($mechanisms[$family] ?? []);
            $viaWrapper += $counts['via_wrapper'];
            foreach (array_keys($mechanisms[$family] ?? []) as $label) {
                $labels[] = $family.': '.$label;
            }
        }

        foreach (['monolog/monolog' => 'monolog', 'sentry/sentry-laravel' => 'sentry', 'sentry/sentry' => 'sentry', 'bugsnag/bugsnag-laravel' => 'bugsnag', 'rollbar/rollbar-laravel' => 'rollbar'] as $package => $label) {
            if (isset($manifests['composer_all'][$package])) {
                $labels[] = $label.' (declared)';
            }
        }
        foreach (LanguagePatterns::JS_LOGGER_PACKAGES as $package) {
            if (isset($manifests['npm_all'][$package])) {
                $labels[] = $package.' (declared)';
            }
        }

        return [
            'by_file' => $byFile,
            'files_logging' => count($byFile),
            'direct' => count($direct),
            'via_wrapper' => $viaWrapper,
            'mechanisms' => $labels,
            'wrapper_files' => array_slice(array_keys($reachCounts), 0, 3),
            'by_family' => $byFamily,
        ];
    }

    private static function isLoggerReference(string $family, string $reference): bool
    {
        return match ($family) {
            // A class named ...Logger, ...Logging or Log(Service|Manager|Facade|Helper) is a logger; a class merely
            // ending in Log (CareLog, AuditLog, ActivityLog) is usually a model and is NOT counted.
            'php' => in_array(ltrim($reference, chr(92)), LanguagePatterns::PHP_LOGGER_TYPES, true) || preg_match('/(?:^|\\\\)(?:[A-Za-z]*(?:Logger|Logging)(?:Interface|Service|Manager|Facade|Helper)?|Log(?:Service|Manager|Facade|Helper))$/', $reference) === 1,
            'js' => in_array(self::npmPackageOf($reference), LanguagePatterns::JS_LOGGER_PACKAGES, true) || preg_match('~(?:^|/)(?:[A-Za-z-]*logger|logging|log)(?:\.[jt]s)?$~i', $reference) === 1,
            'python' => in_array(explode('.', $reference)[0], LanguagePatterns::PYTHON_LOGGER_MODULES, true),
            'go' => in_array($reference, ['log', 'log/slog', 'go.uber.org/zap', 'github.com/sirupsen/logrus', 'github.com/rs/zerolog', 'github.com/rs/zerolog/log'], true),
            'java' => str_starts_with($reference, 'org.slf4j') || str_starts_with($reference, 'java.util.logging') || str_starts_with($reference, 'org.apache.logging') || str_starts_with($reference, 'timber.log') || str_starts_with($reference, 'android.util.Log'),
            'csharp' => str_starts_with($reference, 'Microsoft.Extensions.Logging') || str_starts_with($reference, 'Serilog') || str_starts_with($reference, 'NLog'),
            default => false,
        };
    }

    private static function loggerLabel(string $family, string $reference): string
    {
        return match ($family) {
            'php' => in_array(ltrim($reference, chr(92)), LanguagePatterns::PHP_LOGGER_TYPES, true) ? ltrim($reference, chr(92)) : 'logger-named class '.substr(strrchr($reference, chr(92)) ?: chr(92).$reference, 1),
            'js' => self::npmPackageOf($reference) !== '' && ! str_starts_with($reference, '.') && ! str_starts_with($reference, '@/') ? self::npmPackageOf($reference) : 'local logger module',
            default => $reference,
        };
    }

    /** Seeders, factories, migrations, config, fixtures and translations: lists, not logic. */
    public static function isDataPath(string $path): bool
    {
        return preg_match('~(^|/)(database|config|fixtures?|seeds?|seeders?|factories|migrations|lang|locales?)/~i', $path) === 1;
    }

    public static function familyName(string $family): string
    {
        return match ($family) {
            'php' => 'PHP',
            'js' => 'JavaScript/TypeScript',
            'python' => 'Python',
            'ruby' => 'Ruby',
            'go' => 'Go',
            'java' => 'Java/Kotlin',
            'csharp' => 'C#',
            'rust' => 'Rust',
            'swift' => 'Swift',
            default => $family,
        };
    }

    private static function npmPackageOf(string $specifier): string
    {
        if (str_starts_with($specifier, '.') || str_starts_with($specifier, '/') || str_starts_with($specifier, '@/') || str_starts_with($specifier, '~')) {
            return '';
        }
        $parts = explode('/', $specifier);

        return str_starts_with($specifier, '@') && count($parts) > 1 ? $parts[0].'/'.$parts[1] : $parts[0];
    }

    /**
     * @param  list<FileFact>  $source
     * @param  Manifests  $manifests
     * @return array<string, mixed>
     */
    private function validation(array $source, ImportGraph $graph, array $manifests): array
    {
        $mechanismFiles = [];
        $callSites = 0;
        $formRequests = [];
        $libraries = [];
        $byPath = [];
        foreach ($source as $file) {
            $byPath[$file['path']] = $file;
        }

        foreach ($source as $file) {
            if ($file['is_test']) {
                continue;
            }
            if ($file['signals']['validation_mechanism'] > 0) {
                $mechanismFiles[$file['path']] = true;
                if ($file['family'] === 'php' && $file['kind'] === 'request') {
                    $formRequests[] = $file['path'];
                }
            }
            $callSites += $file['signals']['validation_call'];
        }

        foreach (['spatie/laravel-data', 'respect/validation', 'symfony/validator', 'rakit/validation', 'illuminate/validation', 'laravel/framework'] as $package) {
            if (isset($manifests['composer_all'][$package])) {
                $libraries[] = $package;
            }
        }
        foreach (LanguagePatterns::VALIDATION_PACKAGES['js'] as $package) {
            if (isset($manifests['npm_all'][$package])) {
                $libraries[] = $package;
            }
        }
        foreach ($manifests['python'] as $package) {
            if (in_array($package, LanguagePatterns::VALIDATION_PACKAGES['python'], true)) {
                $libraries[] = $package;
            }
        }

        $inputFiles = [];
        $withoutReference = [];
        foreach ($source as $file) {
            if ($file['is_test'] || $file['signals']['input_read'] === 0) {
                continue;
            }
            $inputFiles[] = $file['path'];
            if ($file['signals']['validation_call'] > 0 || $file['signals']['validation_mechanism'] > 0) {
                continue;
            }
            foreach ($graph->reachable($file['path'], 1) as $reached) {
                if (isset($mechanismFiles[$reached])) {
                    continue 2;
                }
            }
            $withoutReference[] = $file['path'];
        }

        return [
            'mechanism_files' => count($mechanismFiles),
            'form_request_classes' => count($formRequests),
            'call_sites' => $callSites,
            'libraries' => array_values(array_unique($libraries)),
            'input_files' => count($inputFiles),
            'input_files_without_validation_reference' => $withoutReference,
            'assessable' => $this->assessable($source),
        ];
    }

    /**
     * @param  list<FileFact>  $source
     * @return array<string, mixed>
     */
    private function errors(array $source, Stack $stack): array
    {
        $try = 0;
        $catch = 0;
        $external = 0;
        $externalFiles = [];
        $unguarded = [];
        $handlers = [];

        foreach ($source as $file) {
            if ($file['is_test']) {
                continue;
            }
            $try += $file['signals']['try'];
            $catch += $file['signals']['catch'];
            if ($file['signals']['global_handler'] > 0) {
                $handlers[] = $file['path'];
            }
            if ($file['signals']['external_call'] === 0) {
                continue;
            }
            $external += $file['signals']['external_call'];
            $guarded = $file['signals']['catch'] > 0 || $file['signals']['guard'] > 0;
            $externalFiles[] = ['path' => $file['path'], 'sites' => $file['signals']['external_call'], 'guarded' => $guarded];
            if (! $guarded) {
                $unguarded[] = $file['path'].' ('.$file['signals']['external_call'].')';
            }
        }
        usort($externalFiles, fn (array $a, array $b) => $b['sites'] <=> $a['sites']);

        $frameworkHandler = array_values(array_intersect($stack->frameworks, ['laravel', 'symfony', 'django', 'flask', 'fastapi', 'rails', 'express', 'nestjs', 'spring', 'aspnet', 'nuxt', 'next', 'vue', 'angular', 'react']));

        return [
            'try_sites' => $try,
            'catch_sites' => $catch,
            'external_sites' => $external,
            'external_files' => array_slice($externalFiles, 0, $this->config->topCount),
            'external_files_unguarded' => $unguarded,
            'global_handler_files' => $handlers,
            'framework_handler' => $frameworkHandler,
        ];
    }

    /**
     * @param  list<FileFact>  $source
     * @return array<string, mixed>
     */
    private function config(array $source): array
    {
        $sites = 0;
        $files = [];
        $configSites = 0;
        foreach ($source as $file) {
            $configSites += $file['signals']['config_read'];
            if ($file['is_test'] || $file['is_config_path'] || in_array($file['kind'], ['seeder', 'factory', 'migration', 'script', 'infra', 'test'], true) || $file['signals']['env_read'] === 0) {
                continue;
            }
            $sites += $file['signals']['env_read'];
            $files[] = ['path' => $file['path'], 'sites' => $file['signals']['env_read']];
        }
        usort($files, fn (array $a, array $b) => $b['sites'] <=> $a['sites']);

        return [
            'env_sites_outside_config' => $sites,
            'env_files_outside_config' => $files,
            'config_sites' => $configSites,
        ];
    }

    /**
     * @param  list<FileFact>  $source
     * @param  list<FileFact>  $all
     * @param  Manifests  $manifests
     * @return array<string, mixed>
     */
    private function tests(array $source, array $all, ImportGraph $graph, Stack $stack, array $manifests): array
    {
        $testFiles = array_values(array_filter($source, fn (array $f) => $f['is_test']));
        $sourceFiles = array_values(array_filter($source, fn (array $f) => ! $f['is_test']));

        $areaOf = [];
        $stemsByArea = [];
        foreach ($sourceFiles as $file) {
            $areaOf[$file['path']] = $file['area'];
            if ($file['stem'] !== null) {
                $stemsByArea[$file['area']][$file['stem']] = true;
            }
        }

        /** @var array<string, array<string, string>> $linked  area => test path => via */
        $linked = [];
        foreach ($testFiles as $test) {
            // Mirrored path: tests/Unit/Services/DogServiceTest.php mirrors app/Services.
            $segments = array_slice(explode('/', $test['path']), 0, -1);
            $tail = array_map('strtolower', array_values(array_filter($segments, fn (string $s) => ! in_array(strtolower($s), ['tests', 'test', '__tests__', 'spec', 'specs', 'unit', 'feature', 'integration', 'e2e', 'functional', 'browser'], true))));
            foreach (array_keys($stemsByArea + array_flip(array_unique(array_values($areaOf)))) as $area) {
                $areaSegments = array_map('strtolower', explode('/', (string) $area));
                if ($tail !== [] && array_slice($areaSegments, -count($tail)) === $tail) {
                    $linked[$area][$test['path']] = 'path';
                } elseif ($tail !== [] && in_array(end($areaSegments), $tail, true)) {
                    $linked[$area][$test['path']] = 'path';
                }
            }
            // Shared stem: BookingServiceTest links every area with a booking file.
            if ($test['stem'] !== null) {
                foreach ($stemsByArea as $area => $stems) {
                    if (isset($stems[$test['stem']])) {
                        $linked[$area][$test['path']] = 'stem';
                    }
                }
            }
            // Imports/references from the test into the area.
            foreach ($graph->reachable($test['path'], 1) as $reached) {
                if (isset($areaOf[$reached])) {
                    $linked[$areaOf[$reached]][$test['path']] = 'import';
                }
            }
        }

        $frameworks = array_values(array_intersect(self::TEST_FRAMEWORKS, array_map('strtolower', $stack->tooling)));
        foreach (array_keys($manifests['composer_all'] + $manifests['npm_all']) as $package) {
            foreach (self::TEST_FRAMEWORKS as $framework) {
                if (str_contains(strtolower((string) $package), $framework)) {
                    $frameworks[] = $framework;
                }
            }
        }
        foreach ($manifests['python'] as $package) {
            if (in_array($package, ['pytest', 'nose', 'nose2', 'hypothesis'], true)) {
                $frameworks[] = $package;
            }
            if ($package === 'django') {
                $frameworks[] = 'django test runner';
            }
        }
        $frameworks = array_values(array_unique($frameworks));

        return [
            'test_files' => count($testFiles),
            'source_files' => count($sourceFiles),
            'ratio' => count($sourceFiles) > 0 ? round(count($testFiles) / count($sourceFiles), 2) : 0.0,
            'frameworks' => $frameworks,
            'searched' => 'tests/, test/, __tests__/, spec/, e2e/, *.test.*, *.spec.*, test_*.py, *_test.go, *Test.php, *Test.java',
            'linked' => $linked,
        ];
    }

    /**
     * @param  list<FileFact>  $all
     * @param  list<FileFact>  $source
     * @param  array<string, string>  $loggingByFile
     * @param  array<string, array<string, string>>  $linkedTests
     * @return list<array<string, mixed>>
     */
    private function areas(array $all, array $source, array $loggingByFile, array $linkedTests): array
    {
        /** @var array<string, array<string, mixed>> $areas */
        $areas = [];
        foreach ($all as $file) {
            $area = $file['area'];
            $row = $areas[$area] ?? [
                'area' => $area, 'files' => 0, 'source_files' => 0, 'test_files' => 0, 'code_lines' => 0, 'comment_lines' => 0, 'max_depth' => 0,
                'kinds' => [], 'families' => [], 'signals' => array_fill_keys(SourceInventory::SIGNALS, 0),
                'logging_files' => 0, 'logging_via_wrapper' => 0, 'input_files' => 0, 'external_files' => 0, 'external_unguarded_files' => 0, 'env_files' => 0,
                'largest_files' => [], 'stems' => [],
            ];
            $row['files']++;
            $row['code_lines'] += $file['code_lines'];
            $row['comment_lines'] += $file['comment_lines'];
            $row['max_depth'] = max($row['max_depth'], $file['depth']);
            $row['kinds'][$file['kind']] = ($row['kinds'][$file['kind']] ?? 0) + 1;
            if ($file['family'] !== null) {
                $row['families'][$file['family']] = ($row['families'][$file['family']] ?? 0) + 1;
                if ($file['is_test']) {
                    $row['test_files']++;
                } else {
                    $row['source_files']++;
                    if ($file['stem'] !== null) {
                        $row['stems'][$file['stem']] = true;
                    }
                    foreach (SourceInventory::SIGNALS as $signal) {
                        $row['signals'][$signal] += $file['signals'][$signal];
                    }
                    if (isset($loggingByFile[$file['path']])) {
                        $row['logging_files']++;
                        if (str_starts_with($loggingByFile[$file['path']], 'wrapper:')) {
                            $row['logging_via_wrapper']++;
                        }
                    }
                    if ($file['signals']['input_read'] > 0) {
                        $row['input_files']++;
                    }
                    if ($file['signals']['external_call'] > 0) {
                        $row['external_files']++;
                        if ($file['signals']['catch'] === 0 && $file['signals']['guard'] === 0) {
                            $row['external_unguarded_files']++;
                        }
                    }
                    if ($file['signals']['env_read'] > 0 && ! $file['is_config_path']) {
                        $row['env_files']++;
                    }
                    $row['largest_files'][] = ['path' => $file['path'], 'code_lines' => $file['code_lines']];
                }
            }
            $areas[$area] = $row;
        }

        $rows = [];
        foreach ($areas as $row) {
            arsort($row['kinds']);
            arsort($row['families']);
            usort($row['largest_files'], fn (array $a, array $b) => $b['code_lines'] <=> $a['code_lines']);
            $row['largest_files'] = array_slice($row['largest_files'], 0, 3);
            $row['comment_density'] = $row['code_lines'] > 0 ? round($row['comment_lines'] / $row['code_lines'] * 100, 1) : 0.0;
            $row['distinct_stems'] = count($row['stems']);
            unset($row['stems']);
            $row['service_like'] = self::isServiceLike($row);
            $row['input_like'] = self::shareOfKinds($row['kinds'], Naming::INPUT_KINDS) >= 0.5;
            $row['logic'] = $row['source_files'] > 0 && self::shareOfKinds($row['kinds'], Naming::LOGIC_KINDS) >= 0.5;
            $row['assessed'] = $row['families'] !== [] && LanguagePatterns::isAssessed((string) array_key_first($row['families']));
            $tests = $linkedTests[$row['area']] ?? [];
            $via = [];
            foreach ($tests as $how) {
                $via[$how] = ($via[$how] ?? 0) + 1;
            }
            $row['linked_tests'] = count($tests);
            $row['linked_tests_via'] = $via;
            $row['linked_test_examples'] = array_slice(array_keys($tests), 0, 3);
            $rows[] = $row;
        }
        usort($rows, fn (array $a, array $b) => $b['code_lines'] <=> $a['code_lines']);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function isServiceLike(array $row): bool
    {
        $name = strtolower((string) (strrchr('/'.(string) $row['area'], '/') ?: ''));
        $name = ltrim($name, '/');
        if (in_array($name, ['services', 'jobs', 'listeners', 'actions', 'commands', 'domain', 'usecases', 'use-cases', 'handlers', 'repositories', 'application'], true)) {
            return true;
        }

        return self::shareOfKinds((array) $row['kinds'], Naming::SERVICE_KINDS) >= 0.5;
    }

    /**
     * @param  array<string, int>  $kinds
     * @param  list<string>  $wanted
     */
    private static function shareOfKinds(array $kinds, array $wanted): float
    {
        $total = array_sum($kinds);
        if ($total === 0) {
            return 0.0;
        }
        $matched = 0;
        foreach ($kinds as $kind => $count) {
            if (in_array($kind, $wanted, true)) {
                $matched += $count;
            }
        }

        return $matched / $total;
    }

    /**
     * @param  list<FileFact>  $source
     * @return list<array{path: string, code_lines: int}>
     */
    private function largestFiles(array $source): array
    {
        $files = [];
        foreach ($source as $f) {
            if (! $f['is_test'] && ! self::isDataPath($f['path'])) {
                $files[] = ['path' => $f['path'], 'code_lines' => $f['code_lines']];
            }
        }
        usort($files, fn (array $a, array $b) => $b['code_lines'] <=> $a['code_lines']);

        return array_slice($files, 0, $this->config->topCount);
    }

    /**
     * @param  list<FileFact>  $source
     * @return list<array{path: string, name: string, line: int, length: int, method: string}>
     */
    private function largestFunctions(array $source): array
    {
        $functions = [];
        foreach ($source as $file) {
            // A 140-line seeder run() or a config array is data, not a unit anyone should split.
            if ($file['is_test'] || self::isDataPath($file['path'])) {
                continue;
            }
            foreach ($file['functions'] as $function) {
                $functions[] = $function + ['path' => $file['path'], 'method' => match ($file['family']) {
                    'php' => 'ast',
                    'python' => 'indentation',
                    default => 'brace matching',
                }];
            }
        }
        usort($functions, fn (array $a, array $b) => $b['length'] <=> $a['length']);

        return array_map(fn (array $f) => ['path' => $f['path'], 'name' => $f['name'], 'line' => $f['line'], 'length' => $f['length'], 'method' => $f['method']], array_slice($functions, 0, $this->config->topCount));
    }

    /**
     * @param  list<FileFact>  $all
     * @return list<array<string, mixed>>
     */
    private function features(array $all, ImportGraph $graph): array
    {
        /** @var array<string, array{stem: string, files: int, code_lines: int, directories: array<string, true>, kinds: array<string, int>, paths: list<string>}> $clusters */
        $clusters = [];
        foreach ($all as $file) {
            if ($file['stem'] === null || $file['is_test'] || in_array($file['kind'], ['asset', 'doc', 'config', 'infra', 'migration', 'script'], true)) {
                continue;
            }
            $cluster = $clusters[$file['stem']] ?? ['stem' => $file['stem'], 'files' => 0, 'code_lines' => 0, 'directories' => [], 'kinds' => [], 'paths' => []];
            $cluster['files']++;
            $cluster['code_lines'] += $file['code_lines'];
            $cluster['directories'][dirname($file['path'])] = true;
            $cluster['kinds'][$file['kind']] = ($cluster['kinds'][$file['kind']] ?? 0) + 1;
            $cluster['paths'][] = $file['path'];
            $clusters[$file['stem']] = $cluster;
        }

        $rows = [];
        foreach ($clusters as $cluster) {
            if ($cluster['files'] < $this->config->minFeatureFiles) {
                continue;
            }
            $members = array_fill_keys($cluster['paths'], true);
            $connected = [];
            foreach ($cluster['paths'] as $path) {
                foreach ([...$graph->importsOf($path), ...$graph->importedBy($path)] as $other) {
                    if (! isset($members[$other])) {
                        $connected[$other] = true;
                    }
                }
            }
            arsort($cluster['kinds']);
            // One file per conventional kind is the framework's layout working as intended (controller, request,
            // resource, model, policy, service, factory, ...). Only repeated kinds can be sprawl, and even those
            // are often convention: several pages per feature, Store/Update requests, one controller per portal.
            $single = [];
            $repeated = [];
            foreach ($cluster['kinds'] as $kind => $count) {
                if ($count === 1) {
                    $single[] = $kind;
                } else {
                    $repeated[$kind] = $count;
                }
            }
            $rows[] = [
                'stem' => $cluster['stem'],
                'files' => $cluster['files'],
                'directories' => count($cluster['directories']),
                'code_lines' => $cluster['code_lines'],
                'kinds' => $cluster['kinds'],
                'single_kinds' => $single,
                'repeated_kinds' => $repeated,
                'connected_files' => count($connected),
                'examples' => array_slice($cluster['paths'], 0, 6),
            ];
        }
        usort($rows, fn (array $a, array $b) => [$b['files'], $b['code_lines']] <=> [$a['files'], $a['code_lines']]);

        return array_slice($rows, 0, 15);
    }

    /**
     * Clusters from jscpd's pair findings: each pair joins its two locations
     * in a union-find, so A~B and A~C become {A, B, C}.
     *
     * @return array<string, mixed>
     */
    private function duplication(?FindingCollection $jscpd): array
    {
        if ($jscpd === null || $jscpd->count() === 0) {
            return ['clusters' => [], 'pairs' => 0, 'total_duplicated_lines' => 0, 'superseded_pairs' => []];
        }

        $parent = [];
        $find = function (string $key) use (&$parent, &$find): string {
            $parent[$key] ??= $key;

            return $parent[$key] === $key ? $key : ($parent[$key] = $find($parent[$key]));
        };
        $union = function (string $a, string $b) use (&$parent, $find): void {
            $parent[$find($a)] = $find($b);
        };

        $lines = [];
        $snippets = [];
        $pairs = [];
        $totalLines = 0;
        foreach ($jscpd as $finding) {
            if ($finding->ruleId !== 'duplicate-block' || preg_match('/^(\d+) duplicated lines(?: \(\d+ statements\))? also found in (.+):(\d+)\.$/', $finding->message, $m) !== 1) {
                continue;
            }
            $here = $finding->filePath.':'.($finding->line ?? 0);
            $there = $m[2].':'.$m[3];
            $union($here, $there);
            $lines[$here] = max($lines[$here] ?? 0, (int) $m[1]);
            $lines[$there] = max($lines[$there] ?? 0, (int) $m[1]);
            if ($finding->snippet !== null && $finding->snippet !== '') {
                // The same fragment at every location: keep it once so the cluster finding can show the lines.
                $snippets[$here] ??= $finding->snippet;
                $snippets[$there] ??= $finding->snippet;
            }
            $totalLines += (int) $m[1];
            $pairs[] = ['a' => $here, 'b' => $there, 'finding' => $finding];
        }

        $groups = [];
        foreach (array_keys($lines) as $key) {
            $groups[$find($key)][] = $key;
        }

        $clusters = [];
        foreach ($groups as $members) {
            sort($members);
            $block = max(array_map(fn (string $m) => $lines[$m], $members));
            $snippet = null;
            foreach ($members as $member) {
                if (isset($snippets[$member])) {
                    $snippet = $snippets[$member];

                    break;
                }
            }
            $clusters[] = [
                'occurrences' => count($members),
                'lines' => $block,
                'lines_saved' => $block * (count($members) - 1),
                'files' => count(array_unique(array_map(fn (string $m) => explode(':', $m)[0], $members))),
                'locations' => $members,
                'snippet' => $snippet,
            ];
        }
        usort($clusters, fn (array $a, array $b) => [$b['lines_saved'], $b['occurrences']] <=> [$a['lines_saved'], $a['occurrences']]);

        // Reportable: enough lines would go, or the block recurs and still saves something real. Three copies of a
        // 12-line mail builder save 24 lines and are template boilerplate, not a base class waiting to happen.
        // Tests repeat by nature (the same setup in nine test_notify files is a fixture, not a base class); a cluster
        // made only of test files stays in the profile and never becomes a finding.
        $reportable = array_filter($clusters, fn (array $c) => ($c['lines_saved'] >= $this->config->minClusterSavedLines
            || ($c['occurrences'] >= $this->config->minClusterOccurrences && $c['lines_saved'] >= (int) ceil($this->config->minClusterSavedLines / 2)))
            && array_filter($c['locations'], fn (string $l) => ! Naming::isTestName(explode(':', $l)[0])) !== []);
        $covered = [];
        foreach ($reportable as $cluster) {
            foreach ($cluster['locations'] as $location) {
                $covered[$location] = true;
            }
        }
        $superseded = [];
        foreach ($pairs as $pair) {
            if (isset($covered[$pair['a']], $covered[$pair['b']])) {
                $superseded[] = $pair['a'];
            }
        }

        return [
            'clusters' => array_slice($clusters, 0, $this->config->topCount),
            'reportable' => array_values($reportable),
            'pairs' => count($pairs),
            'total_duplicated_lines' => $totalLines,
            'superseded_pairs' => array_values(array_unique($superseded)),
        ];
    }

    /**
     * How far the codebase's prevailing style is from the preset the style
     * analyser checks, plus the preset the repository's own pint.json
     * declares (read as data, never applied). When most files differ, "run
     * the formatter" is the wrong advice until a preset is agreed.
     *
     * @param  list<FileFact>  $source
     * @return array<string, mixed>
     */
    private function style(string $repoPath, array $source, ?FindingCollection $style): array
    {
        $phpFiles = 0;
        foreach ($source as $file) {
            if ($file['family'] === 'php' && ! $file['is_test']) {
                $phpFiles++;
            }
        }
        $flagged = [];
        foreach ($style ?? [] as $finding) {
            $flagged[$finding->filePath] = true;
        }
        $pint = self::readJson($repoPath.'/pint.json');
        $declared = is_string($pint['preset'] ?? null) ? $pint['preset'] : null;
        $ratio = $phpFiles > 0 ? round(count($flagged) / $phpFiles, 2) : 0.0;

        return [
            'checked_preset' => 'laravel',
            'declared_preset' => $declared,
            'declared_rules' => is_array($pint['rules'] ?? null) ? count($pint['rules']) : 0,
            'php_files' => $phpFiles,
            'files_flagged' => count($flagged),
            'ratio' => $ratio,
            'prevailing_style_differs' => $phpFiles >= 10 && $ratio >= 0.4,
        ];
    }

    /** Kinds the framework finds by convention or that are addressed from outside the code: never "unreferenced". */
    private const REACHABILITY_EXEMPT_KINDS = ['config', 'migration', 'seeder', 'factory', 'command', 'provider', 'test', 'policy', 'listener', 'observer', 'page', 'view', 'asset', 'doc', 'infra', 'script', 'type', 'schema'];

    private const REACHABILITY_EXEMPT_PATHS = ['bootstrap/', 'public/', 'database/', 'tests/', 'app/Console/', 'app/Providers/', 'app/Livewire/', 'app/View/', 'app/Filament/', 'app/Nova/', 'app/Exceptions/', '.github/', 'docker/', 'terraform/', 'scripts/', 'bin/'];

    /** Directory names, at any depth, whose modules a framework loads by name (Django template tags, management commands). */
    private const REACHABILITY_EXEMPT_DIRS = ['templatetags', 'management', 'migrations', 'commands', 'fixtures', 'locale', 'template', 'templates', 'stubs', 'stub', 'scaffold', 'scaffolds', 'skeleton', 'boilerplate', 'examples', 'example'];

    /** Basenames that are entry points a framework, runtime or bundler loads by name. */
    private const ENTRY_NAMES = ['app', 'main', 'index', 'ssr', 'bootstrap', 'echo', 'setup', 'entry', 'server', 'client', 'cli', 'artisan', 'manage', 'wsgi', 'asgi', 'settings', 'urls', 'admin', 'apps', 'tasks', 'signals', 'conftest', '__init__', '__main__', 'celery', 'kernel', 'handler', 'lambda_function', 'vite-env', 'env', 'page', 'layout', 'route', 'loading', 'error', 'not-found', 'template', 'middleware', 'application', 'environment', 'boot', 'seeds', 'web', 'api', 'console', 'channels', 'preload', 'renderer'];

    /**
     * Files nothing imports, mentions or globs, after removing what a framework
     * loads by convention. A route file other than the four Laravel defaults is
     * only referenced if something mentions it (bootstrap/app.php, a provider).
     * Also: references to classes under the repository's own namespaces that
     * no file declares, which fail at runtime when reached.
     *
     * @param  list<FileFact>  $all
     * @param  Manifests  $manifests
     * @return array<string, mixed>
     */
    private function reachability(array $all, ImportGraph $graph, array $manifests): array
    {
        $declared = [];
        $supported = 0;
        foreach ($all as $file) {
            foreach ($file['declares'] as $fqcn) {
                $declared[strtolower($fqcn)] = true;
            }
        }
        $autoloadFiles = array_fill_keys(array_map(fn (string $f) => ltrim(str_replace(chr(92), '/', $f), './'), $manifests['composer_files']), true);

        /** @var array<string, array{path: string, area: string, kind: string, code_lines: int}> $candidates */
        $candidates = [];
        $missing = [];
        foreach ($all as $file) {
            if (! in_array($file['family'], ['php', 'js', 'python', 'ruby'], true) || $file['is_test']) {
                continue;
            }
            $supported++;

            if ($file['family'] === 'php') {
                foreach ($file['references'] as $reference) {
                    if ($graph->isOwnPhpName($reference) && ! isset($declared[strtolower(ltrim($reference, chr(92)))])) {
                        $missing[] = ['file' => $file['path'], 'class' => ltrim($reference, chr(92))];
                    }
                }
            }

            $base = strtolower(Naming::baseName($file['path']));
            $isRouteFile = $file['kind'] === 'route';
            if ($isRouteFile && ! in_array($base, ['web', 'api', 'console', 'channels'], true)) {
                // A custom route file is reachable only when something loads it.
            } elseif (in_array($file['kind'], self::REACHABILITY_EXEMPT_KINDS, true) || $isRouteFile || $file['area'] === '(root)' || in_array($base, self::ENTRY_NAMES, true) || $file['is_config_path']) {
                continue;
            }
            foreach (self::REACHABILITY_EXEMPT_PATHS as $prefix) {
                if (str_starts_with($file['path'], $prefix)) {
                    continue 2;
                }
            }
            foreach (explode('/', dirname($file['path'])) as $segment) {
                if (in_array(strtolower($segment), self::REACHABILITY_EXEMPT_DIRS, true)) {
                    continue 2;
                }
            }
            if ($file['family'] === 'php' && $file['declares'] === [] && ! $isRouteFile) {
                continue; // procedural file: nothing to reference it by name
            }
            if (isset($autoloadFiles[$file['path']]) || $graph->isGlobbed($file['path'])) {
                continue;
            }

            $candidates[$file['path']] = ['path' => $file['path'], 'area' => $file['area'], 'kind' => $file['kind'], 'code_lines' => $file['code_lines']];
        }

        // Fixed point: a file referenced only by dead files is dead too (a controller wired only from a route
        // file nothing loads). Start from the files nothing references and keep removing importers that are dead.
        $dead = [];
        do {
            $changed = false;
            foreach ($candidates as $path => $file) {
                if (isset($dead[$path])) {
                    continue;
                }
                $live = array_filter($graph->importedBy($path), fn (string $importer) => ! isset($dead[$importer]) && $importer !== $path);
                if ($live === []) {
                    $dead[$path] = $file;
                    $changed = true;
                }
            }
        } while ($changed);
        $unreferenced = array_values($dead);
        usort($unreferenced, fn (array $a, array $b) => [$a['area'], $b['code_lines']] <=> [$b['area'], $a['code_lines']]);

        return [
            'graph_families' => ['php', 'js', 'python', 'ruby'],
            'supported_files' => $supported,
            'unreferenced' => $unreferenced,
            'unreferenced_lines' => array_sum(array_column($unreferenced, 'code_lines')),
            'missing_own_classes' => $missing,
        ];
    }

    /**
     * @param  list<FileFact>  $source
     * @param  Manifests  $manifests
     * @return array<string, mixed>
     */
    private function dependencies(array $source, DependencyIndex $index, array $manifests, string $repoPath): array
    {
        $composerUse = [];
        $npmUse = [];
        foreach ($source as $file) {
            if ($file['family'] === 'php') {
                foreach ($file['references'] as $reference) {
                    $package = $index->resolvePhp($reference);
                    if ($package !== null) {
                        $composerUse[$package][$file['path']] = true;
                    }
                }
            } elseif ($file['family'] === 'js') {
                foreach ($file['imports'] as $import) {
                    $package = self::npmPackageOf($import);
                    if ($package !== '') {
                        $npmUse[$package][$file['path']] = true;
                    }
                }
            }
        }

        $singleUse = [];
        $neverReferenced = [];
        $wiredByConfig = [];
        $wiring = $this->wiringText($repoPath);
        foreach (array_keys($manifests['composer']) as $package) {
            $package = (string) $package;
            $files = array_keys($composerUse[$package] ?? []);
            if (in_array($package, self::TOOLING_PACKAGES, true) || str_starts_with($package, 'ext-') || $package === 'php') {
                continue;
            }
            if ($files === [] && $index->hasComposerLock) {
                $how = self::wiredWithoutImport($package, $index, $wiring);
                if ($how !== null) {
                    $wiredByConfig[] = $package.' ('.$how.')';
                } else {
                    $neverReferenced[] = 'composer:'.$package;
                }
            } elseif (count($files) > 0 && count($files) <= 2) {
                $singleUse[] = ['package' => 'composer:'.$package, 'files' => $files];
            }
        }
        foreach (array_keys($manifests['npm']) as $package) {
            $package = (string) $package;
            if (self::isNpmTooling($package)) {
                continue;
            }
            $files = array_keys($npmUse[$package] ?? []);
            if (count($files) > 0 && count($files) <= 2) {
                $singleUse[] = ['package' => 'npm:'.$package, 'files' => $files];
            } elseif ($files === []) {
                if (str_contains($wiring, strtolower($package))) {
                    $wiredByConfig[] = $package.' (named in CSS, Blade or config)';
                } else {
                    $neverReferenced[] = 'npm:'.$package;
                }
            }
        }

        $usage = [];
        foreach ($composerUse as $package => $files) {
            $usage['composer:'.$package] = count($files);
        }
        foreach ($npmUse as $package => $files) {
            if (isset($manifests['npm'][$package]) || isset($manifests['npm_all'][$package])) {
                $usage['npm:'.$package] = count($files);
            }
        }
        arsort($usage);

        return [
            'composer_direct' => count($manifests['composer']),
            'composer_dev' => count($manifests['composer_dev']),
            'npm_direct' => count($manifests['npm']),
            'npm_dev' => count($manifests['npm_dev']),
            'single_use' => $singleUse,
            'never_referenced' => $neverReferenced,
            'wired_without_import' => $wiredByConfig,
            'most_used' => array_slice($usage, 0, $this->config->topCount, true),
        ];
    }

    /**
     * Lowercased text of the files where a package can be wired by name rather
     * than by import: config/, .env.example, CSS entry points, bundler configs
     * and Blade layouts (`@vite`, `@import "tailwindcss"`, 's3' drivers).
     */
    private function wiringText(string $repoPath): string
    {
        $parts = [];
        $patterns = ['/config/*.php', '/config/**/*.php', '/.env.example', '/.env.*.example', '/resources/css/*.css', '/resources/css/**/*.css', '/resources/sass/*.scss', '/vite.config.*', '/tailwind.config.*', '/postcss.config.*', '/resources/views/*.blade.php', '/resources/views/layouts/*.blade.php', '/resources/views/components/layouts/*.blade.php', '/bootstrap/providers.php', '/bootstrap/app.php'];
        foreach ($patterns as $pattern) {
            foreach (glob($repoPath.$pattern, GLOB_BRACE) ?: [] as $file) {
                if (is_file($file) && (filesize($file) ?: 0) < 256 * 1024) {
                    $parts[] = (string) file_get_contents($file);
                }
            }
        }

        return strtolower(implode("\n", $parts));
    }

    /**
     * Why a composer package that no PHP file references is nevertheless in
     * use, or null when nothing shows that it is: package discovery, a global
     * helper file, an adapter/driver/provider name, or a name token that
     * appears in config or .env.example (the "s3" of flysystem-aws-s3-v3).
     */
    private static function wiredWithoutImport(string $package, DependencyIndex $index, string $wiring): ?string
    {
        if ($index->composerAutoDiscovered($package)) {
            return 'registered through package discovery or autoload files';
        }
        if (preg_match('~(^socialiteproviders/|flysystem-|-driver$|-adapter$|-provider$|/flysystem|-bridge$|-plugin$|^league/flysystem)~', $package) === 1) {
            return 'an adapter, driver or provider resolved by name';
        }
        $name = substr($package, (int) strpos($package, '/') + 1);
        $generic = ['laravel', 'php', 'package', 'driver', 'adapter', 'provider', 'providers', 'sdk', 'client', 'plugin', 'js', 'vue', 'core', 'lib', 'framework', 'symfony', 'illuminate', 'api', 'aws', 'league', 'spatie', 'the', 'for', 'and', 'with', 'helper', 'helpers', 'support', 'utils', 'common', 'base', 'main'];
        foreach (preg_split('/[-_.]/', strtolower($name)) ?: [] as $token) {
            if (strlen($token) < 3 || in_array($token, $generic, true) || preg_match('/^v\d+$/', $token) === 1) {
                continue;
            }
            if (preg_match('/(?<![a-z0-9])'.preg_quote($token, '/').'(?![a-z0-9])/', $wiring) === 1) {
                return "'{$token}' appears in config or .env.example";
            }
        }

        return null;
    }

    private static function isNpmTooling(string $package): bool
    {
        foreach (['vite', 'tailwindcss', '@tailwindcss/', 'typescript', 'eslint', 'prettier', 'postcss', 'autoprefixer', 'sass', '@types/', '@vitejs/', 'laravel-vite-plugin', 'concurrently', 'vue-tsc', 'playwright', 'husky', 'lint-staged', '@rollup/', 'lightningcss', 'tw-animate-css', 'bootstrap', 'esbuild', 'webpack', 'babel', '@babel/', 'jest', 'vitest', 'cypress', 'nodemon', 'ts-node', 'tsx', 'rimraf', 'cross-env', 'dotenv-cli', 'npm-run-all', 'laravel-mix', 'axios'] as $prefix) {
            if (str_starts_with($package, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<FileFact>  $all
     * @return array<string, mixed>
     */
    private function cohesion(array $all): array
    {
        /** @var array<string, array{directory: string, files: int, stems: array<string, true>, kinds: array<string, int>, examples: list<string>}> $directories */
        $directories = [];
        $hasChildren = [];
        $grabBags = [];
        foreach ($all as $file) {
            // Templates and emails live in flat directories by convention; a dumping ground is about code.
            if ($file['is_test'] || in_array($file['kind'], ['asset', 'doc', 'infra', 'migration', 'view'], true)) {
                continue;
            }
            $dir = dirname($file['path']);
            $dir = $dir === '.' ? '(root)' : $dir;
            $row = $directories[$dir] ?? ['directory' => $dir, 'files' => 0, 'stems' => [], 'kinds' => [], 'examples' => []];
            $row['files']++;
            if ($file['stem'] !== null) {
                $row['stems'][$file['stem']] = true;
            }
            $kind = Naming::kindFromName($file['path']) ?? 'unclassified';
            $row['kinds'][$kind] = ($row['kinds'][$kind] ?? 0) + 1;
            if (count($row['examples']) < 6) {
                $row['examples'][] = basename($file['path']);
            }
            $directories[$dir] = $row;

            $parent = dirname($dir);
            while ($parent !== '.' && $parent !== '' && $parent !== '/') {
                $hasChildren[$parent] = true;
                $parent = dirname($parent);
            }

            if (in_array($file['kind'], ['helper'], true) && $file['code_lines'] >= $this->config->grabBagFileLines) {
                $grabBags[] = ['path' => $file['path'], 'code_lines' => $file['code_lines']];
            }
        }

        $large = [];
        foreach ($directories as $dir => $row) {
            if ($row['files'] < $this->config->minDumpingGroundFiles) {
                continue;
            }
            arsort($row['kinds']);
            $large[] = [
                'directory' => $dir,
                'files' => $row['files'],
                'flat' => ! isset($hasChildren[$dir]),
                'stem_diversity' => $row['files'] > 0 ? round(count($row['stems']) / $row['files'], 2) : 0.0,
                'kinds' => $row['kinds'],
                'examples' => $row['examples'],
            ];
        }
        usort($large, fn (array $a, array $b) => $b['files'] <=> $a['files']);
        usort($grabBags, fn (array $a, array $b) => $b['code_lines'] <=> $a['code_lines']);

        return ['large_directories' => $large, 'grab_bag_files' => $grabBags];
    }

    /**
     * @param  list<FileFact>  $all
     * @param  list<array<string, mixed>>  $areas
     * @return array<string, mixed>
     */
    private function documentation(array $all, array $areas): array
    {
        $readme = false;
        $docs = false;
        foreach ($all as $file) {
            if (preg_match('/^readme(\.[a-z]+)?$/i', $file['path']) === 1) {
                $readme = true;
            }
            if (str_starts_with(strtolower($file['path']), 'docs/')) {
                $docs = true;
            }
        }

        $sparse = [];
        foreach ($areas as $area) {
            if ($area['source_files'] >= 10 && $area['comment_density'] < 2.0) {
                $sparse[] = $area['area'].' ('.$area['comment_density'].'%)';
            }
        }

        return ['readme' => $readme, 'docs_directory' => $docs, 'sparse_areas' => $sparse];
    }

    /**
     * @param  list<FileFact>  $source
     */
    private function assessable(array $source): bool
    {
        foreach ($source as $file) {
            if (! $file['is_test'] && LanguagePatterns::isAssessed((string) $file['family'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Plain sentences for the reviewer: signals that are worth knowing but
     * not certain enough to be findings.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function observations(array $data): array
    {
        $out = [];
        $summary = $data['summary'];
        $tests = $data['tests'];
        $logging = $data['logging'];
        $validation = $data['validation'];
        $errors = $data['errors'];
        $config = $data['config'];
        $dependencies = $data['dependencies'];

        if ($summary['unassessed_families'] !== []) {
            $out[] = 'Absence checks do not run for '.implode(', ', $summary['unassessed_families']).' files; their counts in the area table are observations only.';
        }
        if ($tests['test_files'] === 0 && $tests['frameworks'] !== []) {
            $out[] = 'A test framework is declared ('.implode(', ', $tests['frameworks']).') but no test files were found in '.$tests['searched'].'.';
        }
        $untestedUi = [];
        $untestedLogic = [];
        foreach ($data['areas'] as $area) {
            if ($area['source_files'] >= $this->config->minAreaFilesForTests && $area['linked_tests'] === 0 && $area['test_files'] === 0) {
                if ($area['logic']) {
                    $untestedLogic[] = $area['area'].' ('.$area['source_files'].' files)';
                } elseif (! in_array($area['area'], ['(root)', 'config', 'routes', 'database', 'database/migrations', 'public', 'docs'], true)) {
                    $untestedUi[] = $area['area'].' ('.$area['source_files'].' files)';
                }
            }
        }
        if ($untestedUi !== []) {
            $out[] = 'No test file links to these UI, data or scaffolding areas (not reported as findings): '.implode(', ', $untestedUi).'.';
        }
        foreach ((array) ($logging['by_family'] ?? []) as $family => $counts) {
            $out[] = sprintf('%s: %d of %d files log (%d directly, %d through %s).', self::familyName((string) $family), $counts['files_logging'], $counts['source_files'], $counts['direct'], $counts['via_wrapper'],
                $counts['via_wrapper'] > 0 ? 'another repository file, mostly '.implode(', ', array_slice($logging['wrapper_files'], 0, 2)) : 'a wrapper');
        }
        if ($validation['input_files_without_validation_reference'] !== [] && ($validation['mechanism_files'] > 0 || $validation['call_sites'] > 0)) {
            $out[] = count($validation['input_files_without_validation_reference']).' of '.$validation['input_files'].' input-reading files reference no validation mechanism themselves (the request may be validated elsewhere): '.implode(', ', array_slice($validation['input_files_without_validation_reference'], 0, 5)).'.';
        }
        if ($errors['external_files_unguarded'] !== [] && ($errors['framework_handler'] !== [] || $errors['global_handler_files'] !== [])) {
            $out[] = count($errors['external_files_unguarded']).' files make outbound calls with no try/catch or retry in the same file; a global exception handler exists ('.implode(', ', $errors['framework_handler'] !== [] ? $errors['framework_handler'] : $errors['global_handler_files']).'), so failures are caught but not handled locally: '.implode(', ', array_slice($errors['external_files_unguarded'], 0, 5)).'.';
        }
        if ($config['env_sites_outside_config'] > 0 && $config['env_sites_outside_config'] < $this->config->minEnvSites) {
            $out[] = $config['env_sites_outside_config'].' direct environment read(s) outside config directories (below the reporting threshold).';
        }
        if ($dependencies['never_referenced'] !== []) {
            $out[] = 'Declared but never referenced by namespace or import from any source file, and not wired by package discovery, autoload files, an adapter/driver name, or a name token in config or .env.example: '.implode(', ', array_slice($dependencies['never_referenced'], 0, 10)).'. This is still a list to check, not a list to remove: a package used only through a string the scanner did not recognise looks exactly like this.';
        }
        if ($dependencies['wired_without_import'] !== []) {
            $out[] = 'Declared packages with no import that are in use anyway: '.implode('; ', array_slice($dependencies['wired_without_import'], 0, 10)).'.';
        }
        if ($dependencies['single_use'] !== []) {
            $out[] = count($dependencies['single_use']).' direct dependencies are imported from at most two files.';
        }
        $framework = $summary['frameworks'] !== [];
        $spread = [];
        foreach ($data['features'] as $feature) {
            if ($feature['files'] >= 20 || $feature['directories'] >= 6) {
                $repeated = implode(', ', array_map(fn ($k, $v) => "$v $k", array_keys($feature['repeated_kinds']), $feature['repeated_kinds']));
                $spread[] = sprintf('"%s": %d files in %d directories, one each of %s%s', $feature['stem'], $feature['files'], $feature['directories'],
                    implode(', ', $feature['single_kinds']) ?: 'nothing', $repeated !== '' ? ', plus '.$repeated : '');
            }
        }
        if ($spread !== []) {
            $out[] = 'Feature spread by name stem: '.implode('; ', $spread).'. '
                .($framework ? 'One file per kind is the framework\'s own layout ('.implode(', ', $summary['frameworks']).') and is expected, not sprawl; several pages, Store/Update requests or one controller per portal are usually convention too. ' : '')
                .'Sprawl would be the same concern implemented in several of these files, or files that ignore the layout the rest of the codebase follows; the counts alone do not show that.';
        }
        foreach ($data['cohesion']['large_directories'] as $dir) {
            $out[] = sprintf('%s holds %d files directly (%s, stem diversity %.2f): %s.', $dir['directory'], $dir['files'], $dir['flat'] ? 'flat' : 'with subdirectories', $dir['stem_diversity'], implode(', ', array_map(fn ($k, $v) => "$v $k", array_keys($dir['kinds']), $dir['kinds'])));
        }
        $style = $data['style'];
        if ($style['prevailing_style_differs']) {
            $out[] = sprintf('Style findings cover %d of %d PHP files (%d%%): the codebase\'s prevailing style differs from the %s preset the style check uses%s. "Run the formatter" would rewrite most of the repository; agree a preset first, then run it once in a dedicated commit.',
                $style['files_flagged'], $style['php_files'], (int) round($style['ratio'] * 100), $style['checked_preset'],
                $style['declared_preset'] !== null ? sprintf(' (the repository\'s own pint.json declares the %s preset%s)', $style['declared_preset'], $style['declared_rules'] > 0 ? ' with '.$style['declared_rules'].' rule overrides' : '') : ($style['declared_rules'] > 0 ? ' (the repository\'s own pint.json overrides '.$style['declared_rules'].' rules)' : ''));
        }
        $reach = $data['reachability'];
        if ($reach['unreferenced'] !== []) {
            $out[] = sprintf('%d files (%s lines) are imported, mentioned or globbed by nothing in the repository, after excluding framework entry points and auto-discovered kinds: %s. Treat them as dead unless proven otherwise: auto-registration a scanner cannot see is the usual reason a live file looks unreferenced.',
                count($reach['unreferenced']), number_format((int) $reach['unreferenced_lines']), implode(', ', array_map(fn (array $u) => $u['path'], array_slice($reach['unreferenced'], 0, 10))).(count($reach['unreferenced']) > 10 ? ' (+'.(count($reach['unreferenced']) - 10).' more)' : ''));
        }
        if ($reach['missing_own_classes'] !== []) {
            $out[] = 'References to classes under the repository\'s own namespaces that no file declares: '.implode(', ', array_map(fn (array $m) => $m['class'].' (from '.$m['file'].')', array_slice($reach['missing_own_classes'], 0, 8))).'.';
        }
        if ($data['documentation']['sparse_areas'] !== []) {
            $out[] = 'Comment density under 2% in: '.implode(', ', $data['documentation']['sparse_areas']).'.';
        }
        if (! $data['documentation']['readme']) {
            $out[] = 'No README at the repository root.';
        }

        return $out;
    }

    /**
     * @return Manifests
     */
    private function manifests(string $repoPath): array
    {
        $composer = self::readJson($repoPath.'/composer.json');
        $package = self::readJson($repoPath.'/package.json');
        $python = [];
        foreach (['requirements.txt', 'requirements/base.txt', 'requirements-dev.txt', 'pyproject.toml', 'Pipfile', 'setup.py', 'setup.cfg'] as $name) {
            $file = $repoPath.'/'.$name;
            if (is_file($file) && (filesize($file) ?: 0) < 256 * 1024) {
                if (preg_match_all('/^\s*["\']?([A-Za-z][A-Za-z0-9_.-]*)/m', (string) file_get_contents($file), $m) > 0) {
                    foreach ($m[1] as $name) {
                        $python[strtolower(str_replace('_', '-', $name))] = true;
                    }
                }
            }
        }

        $composerRequire = array_fill_keys(array_map('strval', array_keys((array) ($composer['require'] ?? []))), true);
        $composerDev = array_fill_keys(array_map('strval', array_keys((array) ($composer['require-dev'] ?? []))), true);
        $npm = array_fill_keys(array_map('strval', array_keys((array) ($package['dependencies'] ?? []))), true);
        $npmDev = array_fill_keys(array_map('strval', array_keys((array) ($package['devDependencies'] ?? []))), true);

        $composerFiles = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ((array) (($composer[$section] ?? [])['files'] ?? []) as $file) {
                $composerFiles[] = (string) $file;
            }
        }

        return [
            'composer' => array_diff_key($composerRequire, ['php' => true]),
            'composer_dev' => $composerDev,
            'composer_all' => $composerRequire + $composerDev,
            'composer_files' => $composerFiles,
            'npm' => $npm,
            'npm_dev' => $npmDev,
            'npm_all' => $npm + $npmDev,
            'python' => array_keys($python),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $file): array
    {
        if (! is_file($file) || (filesize($file) ?: 0) > 512 * 1024) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }
}
