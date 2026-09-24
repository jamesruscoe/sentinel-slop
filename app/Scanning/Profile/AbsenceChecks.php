<?php

declare(strict_types=1);

namespace App\Scanning\Profile;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;

/**
 * Structural findings derived from the profile. Deliberately conservative:
 * each check fires only when the mechanism it looks for is absent from the
 * whole population it examined, and every message states the counts and
 * the paths it drew on so the claim can be checked. Anything less certain
 * stays in the profile's observations for the reviewer.
 *
 * What each check can miss (by design, it then stays silent):
 *   no-tests-*            tests in unconventional directories, tests kept in another repository
 *   no-logging-*          a logging helper not named like one and not importing a logger, logging done
 *                         by a vendor base class, audit logging through events; framework handlers still
 *                         log uncaught exceptions and the message says so
 *   no-input-validation   a home-grown validation library, validation in a separate package, casting DTOs
 *   env-read-outside-config  environment read through a wrapper helper
 *   duplication-cluster   clones below jscpd's minimum size, renamed-identifier clones, Blade templates
 *   dumping-ground-directory  a grab bag split into subdirectories
 *   unguarded-external-calls  never fires when any global exception handler exists (framework or file)
 */
final class AbsenceChecks
{
    public const TOOL = 'profile';

    public function __construct(private readonly ProfileConfig $config = new ProfileConfig) {}

    public function run(RepositoryProfile $profile): FindingCollection
    {
        $findings = new FindingCollection;
        $summary = $profile->section('summary');
        $areas = $profile->section('areas');
        $tests = $profile->section('tests');
        $logging = $profile->section('logging');
        $validation = $profile->section('validation');
        $errors = $profile->section('errors');
        $config = $profile->section('config');
        $duplication = $profile->section('duplication');
        $cohesion = $profile->section('cohesion');

        $assessed = ($summary['assessed_families'] ?? []) !== [];
        $sourceFiles = (int) ($summary['source_files'] ?? 0);
        $isApplication = $this->isApplication($areas);

        // Tests.
        if ($assessed && (int) ($tests['test_files'] ?? 0) === 0 && $sourceFiles >= $this->config->minSourceFilesForTests) {
            $frameworks = (array) ($tests['frameworks'] ?? []);
            $findings->add($this->finding('no-tests-at-all', $frameworks === [] ? Severity::High : Severity::Medium, '',
                sprintf('No test files exist: %d source files and no tests in %s%s.', $sourceFiles, (string) ($tests['searched'] ?? 'the usual test directories'),
                    $frameworks === [] ? ', and no test framework is declared' : ', although '.implode(', ', $frameworks).' is declared')));
        } elseif ((int) ($tests['test_files'] ?? 0) > 0) {
            foreach ($areas as $area) {
                if (! $area['assessed'] || ! $area['logic'] || $area['linked_tests'] > 0 || $area['test_files'] > 0 || $area['source_files'] < $this->config->minAreaFilesForTests) {
                    continue;
                }
                $findings->add($this->finding('no-tests-in-area', $area['service_like'] ? Severity::High : Severity::Medium, (string) $area['area'],
                    sprintf('%s has %d source files (%s) and none of the %d test files links to it by path, by name or by import. Examples: %s.',
                        $area['area'], $area['source_files'], self::kinds($area['kinds']), (int) ($tests['test_files'] ?? 0), self::examples($area['largest_files']))));
            }
        }

        // Logging.
        $loggingFiles = (int) ($logging['files_logging'] ?? 0);
        if ($assessed && $isApplication && $loggingFiles === 0 && $sourceFiles >= $this->config->minSourceFilesForLogging) {
            $findings->add($this->finding('no-logging-anywhere', Severity::Medium, '',
                sprintf('No logging was found in any of the %d source files: no logger import, no logging call and no file that references a logger. Uncaught exceptions are still logged by the framework handler where one exists; handled failures and state transitions are not logged anywhere.', $sourceFiles)));
        } elseif ($loggingFiles > 0) {
            foreach ($areas as $area) {
                if (! $area['assessed'] || ! $area['service_like'] || $area['logging_files'] > 0 || $area['source_files'] < $this->config->minServiceFilesForLogging) {
                    continue;
                }
                $family = (string) (array_key_first((array) $area['families']) ?? 'php');
                $familyCounts = (array) (($logging['by_family'] ?? [])[$family] ?? []);
                $familyLogging = (int) ($familyCounts['files_logging'] ?? 0);
                $elsewhere = $familyLogging === 0
                    ? sprintf('no %s file anywhere in the repository logs', RepositoryProfiler::familyName($family))
                    : sprintf('%d other %s files do, via %s', $familyLogging, RepositoryProfiler::familyName($family), implode(', ', array_slice((array) ($familyCounts['mechanisms'] ?? []), 0, 3)));
                $findings->add($this->finding('no-logging-in-layer', Severity::Medium, (string) $area['area'],
                    sprintf('%s has %d source files (%s) and none of them logs: no logger import, no logging call, and none imports a repository file that logs (%s). Uncaught exceptions still reach the framework handler; handled failures and state transitions in this layer are not logged anywhere. Examples: %s.',
                        $area['area'], $area['source_files'], self::kinds($area['kinds']), $elsewhere, self::examples($area['largest_files']))));
            }
        }

        // Input validation: the mechanism must be absent from the whole repository, not just the reading file.
        if ($assessed && (bool) ($validation['assessable'] ?? false)
            && (int) ($validation['input_files'] ?? 0) >= $this->config->minInputFilesForValidation
            && (int) ($validation['mechanism_files'] ?? 0) === 0 && (int) ($validation['call_sites'] ?? 0) === 0
            && array_diff((array) ($validation['libraries'] ?? []), ['laravel/framework', 'illuminate/validation']) === []) {
            $findings->add($this->finding('no-input-validation', Severity::High, '',
                sprintf('%d files read request input and no validation mechanism exists anywhere: no Form Request or schema class, no validation call, and no validation library declared. Files reading input: %s.',
                    (int) $validation['input_files'], self::examples(array_map(fn (string $p) => ['path' => $p], (array) $validation['input_files_without_validation_reference'])))));
        }

        // Environment reads outside config.
        $envFiles = (array) ($config['env_files_outside_config'] ?? []);
        if ($assessed && (int) ($config['env_sites_outside_config'] ?? 0) >= $this->config->minEnvSites && count($envFiles) >= $this->config->minEnvFiles) {
            $findings->add($this->finding('env-read-outside-config', Severity::Medium, '',
                sprintf('%d direct environment reads in %d files outside config directories (%d config reads elsewhere). Read the environment once in config and inject the values; on Laravel, env() outside config/ returns null once config is cached. Files: %s.',
                    (int) $config['env_sites_outside_config'], count($envFiles), (int) ($config['config_sites'] ?? 0), self::examples($envFiles, 6, 'sites'))));
        }

        // Outbound calls with no guard anywhere: only when no global handler of any kind exists.
        $unguarded = (array) ($errors['external_files_unguarded'] ?? []);
        if ($assessed && $unguarded !== [] && ($errors['framework_handler'] ?? []) === [] && ($errors['global_handler_files'] ?? []) === []
            && (int) ($errors['external_sites'] ?? 0) >= $this->config->minExternalSitesUnguarded && (int) ($errors['catch_sites'] ?? 0) === 0) {
            $findings->add($this->finding('unguarded-external-calls', Severity::Medium, '',
                sprintf('%d outbound HTTP/SDK call sites across %d files, no try/catch or retry anywhere in those files, and no global exception handler was found. Files: %s.',
                    (int) $errors['external_sites'], count($unguarded), implode(', ', array_slice($unguarded, 0, 6)))));
        }

        // Duplication clusters (from jscpd pairs).
        foreach ((array) ($duplication['reportable'] ?? []) as $cluster) {
            $first = explode(':', (string) $cluster['locations'][0]);
            $findings->add(new Finding(self::TOOL, 'duplication-cluster', FindingCategory::Duplication,
                $cluster['lines_saved'] >= $this->config->highClusterSavedLines ? Severity::High : Severity::Medium,
                $first[0], (int) ($first[1] ?? 0),
                sprintf('The same %d-line block appears %d times in %d files (%d lines could be removed by extracting it once): %s.%s',
                    $cluster['lines'], $cluster['occurrences'], $cluster['files'], $cluster['lines_saved'], implode(', ', array_slice($cluster['locations'], 0, 8)).(count($cluster['locations']) > 8 ? ' (+'.(count($cluster['locations']) - 8).' more)' : ''),
                    isset($cluster['snippet']) ? ' The snippet shows the first lines of the block.' : ''),
                isset($cluster['snippet']) ? (string) $cluster['snippet'] : null));
        }

        // Reachability: dead files and references to classes that do not exist.
        $reachability = $profile->section('reachability');
        $byArea = [];
        foreach ((array) ($reachability['unreferenced'] ?? []) as $file) {
            $byArea[(string) $file['area']][] = $file;
        }
        foreach ($byArea as $area => $files) {
            $lines = array_sum(array_column($files, 'code_lines'));
            $findings->add($this->finding('unreferenced-code', Severity::Medium, $area,
                sprintf('%d file%s in %s (%s lines) %s imported, mentioned or globbed by nothing in the repository, after excluding framework entry points and auto-discovered kinds: %s. Confirm %s unused and delete %s rather than fixing, logging or testing %s; auto-registration a scanner cannot see (package discovery, auto-imports) is the usual reason a live file looks unreferenced.',
                    count($files), count($files) === 1 ? '' : 's', $area, number_format($lines), count($files) === 1 ? 'is' : 'are',
                    implode(', ', array_map(fn (array $f) => $f['path'].' ('.$f['code_lines'].')', array_slice($files, 0, 8))).(count($files) > 8 ? ' (+'.(count($files) - 8).' more)' : ''),
                    count($files) === 1 ? 'it is' : 'they are', count($files) === 1 ? 'it' : 'them', count($files) === 1 ? 'it' : 'them')));
        }
        $unreferencedPaths = array_fill_keys(array_map(fn (array $f) => (string) $f['path'], (array) ($reachability['unreferenced'] ?? [])), true);
        foreach ((array) ($reachability['missing_own_classes'] ?? []) as $missing) {
            $dead = isset($unreferencedPaths[$missing['file']]);
            $findings->add(new Finding(self::TOOL, 'missing-own-class', FindingCategory::Structure, $dead ? Severity::Medium : Severity::High, (string) $missing['file'], null,
                sprintf('%s references %s, which no file in the repository declares; PHP throws a class-not-found error when that line executes.%s', $missing['file'], $missing['class'],
                    $dead ? ' The referencing file is itself unreferenced: delete both.' : ' Implement the class or remove the reference.')));
        }

        // Dumping-ground directories.
        foreach ((array) ($cohesion['large_directories'] ?? []) as $dir) {
            if (! $dir['flat'] || $dir['stem_diversity'] < $this->config->dumpingGroundStemDiversity || count($dir['kinds']) < $this->config->minDumpingGroundKinds) {
                continue;
            }
            $findings->add($this->finding('dumping-ground-directory', Severity::Medium, (string) $dir['directory'],
                sprintf('%s holds %d files directly with no subdirectories; they share almost no names (%d%% distinct stems) and mix %d kinds (%s). Examples: %s.',
                    $dir['directory'], $dir['files'], (int) round($dir['stem_diversity'] * 100), count($dir['kinds']), self::kinds($dir['kinds']), implode(', ', $dir['examples']))));
        }

        return $findings;
    }

    private function finding(string $rule, Severity $severity, string $path, string $message): Finding
    {
        return new Finding(self::TOOL, $rule, FindingCategory::Structure, $severity, $path, null, $message);
    }

    /**
     * An application receives input or renders UI; a library does not, and
     * "no logging" is normal for a library.
     *
     * @param  array<mixed>  $areas
     */
    private function isApplication(array $areas): bool
    {
        foreach ($areas as $area) {
            $kinds = is_array($area) ? (array) ($area['kinds'] ?? []) : [];
            foreach (['controller', 'handler', 'route', 'page', 'view', 'command', 'job'] as $kind) {
                if (($kinds[$kind] ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, int>  $kinds
     */
    private static function kinds(array $kinds): string
    {
        $parts = [];
        foreach (array_slice($kinds, 0, 4, true) as $kind => $count) {
            $parts[] = $count.' '.$kind;
        }

        return implode(', ', $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $files
     */
    private static function examples(array $files, int $limit = 5, ?string $countKey = null): string
    {
        $parts = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $parts[] = (string) $file['path'].($countKey !== null && isset($file[$countKey]) ? ' ('.$file[$countKey].')' : '');
        }

        return implode(', ', $parts).(count($files) > $limit ? ' (+'.(count($files) - $limit).' more)' : '');
    }
}
