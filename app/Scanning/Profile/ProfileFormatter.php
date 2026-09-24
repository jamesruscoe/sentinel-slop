<?php

declare(strict_types=1);

namespace App\Scanning\Profile;

/**
 * Renders a RepositoryProfile as plain text: the same rendering goes to the
 * CLI, the results page and (later) the reviewer's payload, so what a person
 * reads and what the model reads are one thing.
 */
final class ProfileFormatter
{
    public static function render(RepositoryProfile $profile, int $areaLimit = 25): string
    {
        $d = $profile->toArray();
        $s = $d['summary'] ?? [];
        $lines = [];

        $lines[] = sprintf('Files: %d (%d source, %d test). Code lines: %s, comment lines: %s (%.1f%%). Deepest path: %d levels.',
            $s['files'] ?? 0, $s['source_files'] ?? 0, $s['test_files'] ?? 0, number_format((int) ($s['code_lines'] ?? 0)), number_format((int) ($s['comment_lines'] ?? 0)), (float) ($s['comment_density'] ?? 0), (int) ($s['max_depth'] ?? 0));
        $lines[] = 'Languages by file: '.self::pairs((array) ($s['families'] ?? [])).'. Absence checks assess: '.(implode(', ', (array) ($s['assessed_families'] ?? [])) ?: 'none').'.';
        $lines[] = '';

        $lines[] = 'AREAS (source files only for signal counts; log = files that log, incl. via wrapper; try/catch, ext = outbound call sites, val = validation sites, env = direct env reads outside config, tests = linked test files)';
        $lines[] = sprintf('%-34s %5s %7s %5s %4s %4s %4s %4s %4s %5s %5s  %s', 'area', 'files', 'lines', 'cmt%', 'log', 'try', 'ext', 'val', 'env', 'tests', 'depth', 'kinds');
        foreach (array_slice((array) ($d['areas'] ?? []), 0, $areaLimit) as $a) {
            $lines[] = sprintf('%-34s %5d %7s %5s %4d %4d %4d %4d %4d %5d %5d  %s',
                mb_strimwidth((string) $a['area'], 0, 34, '…'), $a['files'], number_format((int) $a['code_lines']), $a['comment_density'], $a['logging_files'], $a['signals']['try'], $a['signals']['external_call'], $a['signals']['validation_call'] + $a['signals']['validation_mechanism'], $a['env_files'], $a['linked_tests'], $a['max_depth'], self::pairs((array) $a['kinds'], 4));
        }
        if (count((array) ($d['areas'] ?? [])) > $areaLimit) {
            $lines[] = sprintf('(+%d smaller areas)', count((array) $d['areas']) - $areaLimit);
        }
        $lines[] = '';

        $lines[] = 'LARGEST FILES: '.implode('; ', array_map(fn ($f) => $f['path'].' ('.number_format((int) $f['code_lines']).')', (array) ($d['largest_files'] ?? [])));
        $lines[] = 'LONGEST FUNCTIONS: '.implode('; ', array_map(fn ($f) => sprintf('%s() in %s:%d (%d lines, %s)', $f['name'], $f['path'], $f['line'], $f['length'], $f['method']), (array) ($d['largest_functions'] ?? [])));
        $lines[] = '';

        $lines[] = 'FEATURE SPREAD (files grouped by name stem; one file per kind is the framework layout, not sprawl; "connected" = other files one import away)';
        foreach ((array) ($d['features'] ?? []) as $f) {
            $repeated = self::pairs((array) ($f['repeated_kinds'] ?? []), 5);
            $lines[] = sprintf('  %-16s %3d files in %2d dirs, %6s lines, %3d connected. One each: %s%s. e.g. %s', $f['stem'], $f['files'], $f['directories'], number_format((int) $f['code_lines']), $f['connected_files'],
                implode(', ', (array) ($f['single_kinds'] ?? [])) ?: 'none', $repeated !== '' ? '; repeated: '.$repeated : '', implode(', ', array_slice((array) $f['examples'], 0, 2)));
        }
        $lines[] = '';

        $dup = $d['duplication'] ?? [];
        $lines[] = sprintf('DUPLICATION: %d jscpd pairs, %s duplicated lines in total, %d clusters (%d reportable).', $dup['pairs'] ?? 0, number_format((int) ($dup['total_duplicated_lines'] ?? 0)), count((array) ($dup['clusters'] ?? [])), count((array) ($dup['reportable'] ?? [])));
        foreach ((array) ($dup['clusters'] ?? []) as $c) {
            $lines[] = sprintf('  %d-line block x%d in %d files (%d lines saved): %s', $c['lines'], $c['occurrences'], $c['files'], $c['lines_saved'], implode(', ', array_slice((array) $c['locations'], 0, 5)).(count($c['locations']) > 5 ? ' (+'.(count($c['locations']) - 5).')' : ''));
        }
        $lines[] = '';

        $t = $d['tests'] ?? [];
        $lines[] = sprintf('TESTS: %d test files for %d source files (ratio %.2f). Frameworks: %s. Searched: %s.', $t['test_files'] ?? 0, $t['source_files'] ?? 0, (float) ($t['ratio'] ?? 0), implode(', ', (array) ($t['frameworks'] ?? [])) ?: 'none declared', $t['searched'] ?? '');
        $untested = array_filter((array) ($d['areas'] ?? []), fn ($a) => $a['logic'] && $a['source_files'] >= 5 && $a['linked_tests'] === 0 && $a['test_files'] === 0);
        $lines[] = '  Logic areas with no linked test: '.(implode(', ', array_map(fn ($a) => $a['area'].' ('.$a['source_files'].')', $untested)) ?: 'none');
        $lines[] = '';

        $l = $d['logging'] ?? [];
        $lines[] = sprintf('LOGGING: %d files log (%d directly, %d through another repository file). Mechanisms: %s.', $l['files_logging'] ?? 0, $l['direct'] ?? 0, $l['via_wrapper'] ?? 0, implode(', ', (array) ($l['mechanisms'] ?? [])) ?: 'none found');
        foreach ((array) ($l['by_family'] ?? []) as $family => $counts) {
            $lines[] = sprintf('  %s: %d of %d files (%d direct, %d via wrapper)', RepositoryProfiler::familyName((string) $family), $counts['files_logging'], $counts['source_files'], $counts['direct'], $counts['via_wrapper']);
        }
        if (($l['wrapper_files'] ?? []) !== []) {
            $lines[] = '  Files most often logged through: '.implode(', ', (array) $l['wrapper_files']);
        }
        $noLog = array_filter((array) ($d['areas'] ?? []), fn ($a) => $a['service_like'] && $a['logging_files'] === 0 && $a['source_files'] > 0);
        $lines[] = '  Service-like areas with no logging: '.(implode(', ', array_map(fn ($a) => $a['area'].' ('.$a['source_files'].')', $noLog)) ?: 'none');
        $lines[] = '';

        $v = $d['validation'] ?? [];
        $lines[] = sprintf('VALIDATION: %d files declare a mechanism (%d Form Request classes), %d validation call sites, libraries: %s. %d files read input; %d of them reference no validation: %s',
            $v['mechanism_files'] ?? 0, $v['form_request_classes'] ?? 0, $v['call_sites'] ?? 0, implode(', ', (array) ($v['libraries'] ?? [])) ?: 'none', $v['input_files'] ?? 0, count((array) ($v['input_files_without_validation_reference'] ?? [])), implode(', ', array_slice((array) ($v['input_files_without_validation_reference'] ?? []), 0, 8)) ?: 'none');
        $lines[] = '';

        $e = $d['errors'] ?? [];
        $lines[] = sprintf('ERROR HANDLING: %d try, %d catch sites. %d outbound call sites in %d files; %d files unguarded (no catch/retry in file): %s. Global handlers: %s.',
            $e['try_sites'] ?? 0, $e['catch_sites'] ?? 0, $e['external_sites'] ?? 0, count((array) ($e['external_files'] ?? [])), count((array) ($e['external_files_unguarded'] ?? [])), implode(', ', array_slice((array) ($e['external_files_unguarded'] ?? []), 0, 8)) ?: 'none', implode(', ', array_merge((array) ($e['framework_handler'] ?? []), (array) ($e['global_handler_files'] ?? []))) ?: 'none found');
        $lines[] = '';

        $c = $d['config'] ?? [];
        $lines[] = sprintf('CONFIG: %d direct env reads outside config dirs in %d files (%d config reads): %s', $c['env_sites_outside_config'] ?? 0, count((array) ($c['env_files_outside_config'] ?? [])), $c['config_sites'] ?? 0, implode(', ', array_map(fn ($f) => $f['path'].' ('.$f['sites'].')', array_slice((array) ($c['env_files_outside_config'] ?? []), 0, 8))) ?: 'none');
        $lines[] = '';

        $dep = $d['dependencies'] ?? [];
        $lines[] = sprintf('DEPENDENCIES: composer %d direct + %d dev, npm %d direct + %d dev. Most used: %s.', $dep['composer_direct'] ?? 0, $dep['composer_dev'] ?? 0, $dep['npm_direct'] ?? 0, $dep['npm_dev'] ?? 0, self::pairs((array) ($dep['most_used'] ?? []), 8) ?: 'n/a');
        $lines[] = '  Used from at most two files: '.(implode(', ', array_map(fn ($p) => $p['package'].' ('.implode(', ', $p['files']).')', (array) ($dep['single_use'] ?? []))) ?: 'none');
        $lines[] = '  In use without an import (discovery, autoload files, driver name, config token): '.(implode('; ', (array) ($dep['wired_without_import'] ?? [])) ?: 'none');
        $lines[] = '  Never referenced from source, config or .env.example: '.(implode(', ', (array) ($dep['never_referenced'] ?? [])) ?: 'none');
        $lines[] = '';

        $coh = $d['cohesion'] ?? [];
        $lines[] = 'COHESION: large directories: '.(implode('; ', array_map(fn ($x) => sprintf('%s (%d files, %s, diversity %.2f, %s)', $x['directory'], $x['files'], $x['flat'] ? 'flat' : 'nested', $x['stem_diversity'], self::pairs((array) $x['kinds'], 4)), (array) ($coh['large_directories'] ?? []))) ?: 'none over threshold');
        $lines[] = '  Grab-bag helper files: '.(implode(', ', array_map(fn ($x) => $x['path'].' ('.$x['code_lines'].')', (array) ($coh['grab_bag_files'] ?? []))) ?: 'none');
        $lines[] = '';

        $r = $d['reachability'] ?? [];
        $unref = (array) ($r['unreferenced'] ?? []);
        $lines[] = sprintf('REACHABILITY (import graph plus string mentions and globs; framework entry points and auto-discovered kinds excluded): %d of %d files are referenced by nothing (%s lines).', count($unref), $r['supported_files'] ?? 0, number_format((int) ($r['unreferenced_lines'] ?? 0)));
        foreach (array_slice($unref, 0, 15) as $u) {
            $lines[] = sprintf('  UNREFERENCED %s (%s, %d lines)', $u['path'], $u['kind'], $u['code_lines']);
        }
        if (count($unref) > 15) {
            $lines[] = sprintf('  (+%d more)', count($unref) - 15);
        }
        foreach ((array) ($r['missing_own_classes'] ?? []) as $m) {
            $lines[] = sprintf('  MISSING CLASS %s referenced from %s is declared nowhere in the repository', $m['class'], $m['file']);
        }
        $lines[] = '';

        $doc = $d['documentation'] ?? [];
        $lines[] = sprintf('DOCUMENTATION: README %s, docs/ %s, sparse areas (<2%% comments): %s.', ($doc['readme'] ?? false) ? 'present' : 'missing', ($doc['docs_directory'] ?? false) ? 'present' : 'absent', implode(', ', (array) ($doc['sparse_areas'] ?? [])) ?: 'none');
        $lines[] = '';

        $lines[] = 'OBSERVATIONS (for the reviewer; not findings):';
        foreach ((array) ($d['observations'] ?? []) as $o) {
            $lines[] = '  - '.$o;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, int|float>  $pairs
     */
    private static function pairs(array $pairs, int $limit = 6): string
    {
        $parts = [];
        foreach (array_slice($pairs, 0, $limit, true) as $key => $value) {
            $parts[] = $value.' '.$key;
        }

        return implode(', ', $parts).(count($pairs) > $limit ? ', …' : '');
    }
}
