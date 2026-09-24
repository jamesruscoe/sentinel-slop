<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use App\Scanning\Exceptions\AnalyserFailedException;

/**
 * jscpd copy/paste detection with the bundled config (repo .jscpd.json,
 * package.json "jscpd" and .gitignore are never consulted).
 */
final class JscpdAnalyser extends ProcessAnalyser
{
    public function name(): string
    {
        return 'jscpd';
    }

    public function supports(Stack $stack): bool
    {
        return true;
    }

    public function run(string $path): FindingCollection
    {
        $workDir = $this->workDir($path);

        $result = $this->execute([
            ...$this->tools->command('jscpd'),
            '--config', $this->options->configFile('jscpd.json'),
            // Single-file components are tokenised whole as JavaScript. jscpd's own "vue" format splits each
            // file into per-block sources (Foo.vue:typescript, Foo.vue:html), applies minLines per block and
            // so misses most page-level duplication, which is exactly the DRY signal we want.
            '--formats-exts', 'javascript:vue,svelte',
            // jscpd honours every .gitignore up the directory tree. Sentinel Slop's own .gitignore excludes
            // storage/app/scans/*, so without this flag jscpd analysed zero files on every real scan, and a
            // repository's own .gitignore could hide files from it. The config key is not enough.
            '--no-gitignore',
            '--output', $workDir,
            '--reporters', 'json',
            '--silent',
            $path,
        ], $workDir);

        if ($result->exitCode !== 0) {
            throw new AnalyserFailedException("jscpd exited with {$result->exitCode}: ".substr($result->stderr.$result->stdout, 0, 400));
        }

        $report = $this->readJsonFile($workDir.'/jscpd-report.json', 'its report');
        // The formats in the bundled config plus the SFC extensions mapped above. jscpd only counts a file as a
        // source once it clears minLines/minTokens, so only files comfortably above both are expected.
        $this->assertCoverage($path, ['php', 'js', 'jsx', 'ts', 'tsx', 'py', 'vue', 'svelte'], (int) ($report['statistics']['total']['sources'] ?? 0), '0 source files scanned', function (string $absolute): bool {
            $size = filesize($absolute);
            if ($size === false || $size > 1024 * 1024) {
                return false;
            }
            $contents = (string) file_get_contents($absolute);

            return substr_count($contents, "\n") >= 12 && preg_match_all('/\w+|[^\s\w]/', $contents) >= 100;
        });
        $findings = new FindingCollection;
        $root = rtrim(str_replace(chr(92), '/', $path), '/').'/';
        // jscpd reports Windows extended-length paths (the //?/ prefix once slashes are normalised); drop it before relativising.
        $relative = function (string $name) use ($root): string {
            $name = str_replace(chr(92), '/', $name);
            if (str_starts_with($name, '//?/')) {
                $name = substr($name, 4);
            }

            return str_starts_with($name, $root) ? substr($name, strlen($root)) : $name;
        };

        foreach ((array) ($report['duplicates'] ?? []) as $duplicate) {
            $first = (array) ($duplicate['firstFile'] ?? []);
            $second = (array) ($duplicate['secondFile'] ?? []);
            $lines = (int) ($duplicate['lines'] ?? 0);
            $line = isset($second['start']) ? (int) $second['start'] : null;
            $file = $relative((string) ($second['name'] ?? ''));
            $fragment = isset($duplicate['fragment']) ? (string) $duplicate['fragment'] : '';
            $statements = self::statementsIn($fragment);

            // A block that is mostly declarations, imports, braces and comments (a class skeleton with a one-line
            // authorize()) is not duplication anyone should extract: a base class would couple unrelated classes.
            if ($statements < self::MIN_STATEMENTS) {
                continue;
            }

            $findings->add(new Finding('jscpd', 'duplicate-block', FindingCategory::Duplication, $lines >= 30 && $statements >= 10 ? Severity::Medium : Severity::Low, $file, $line,
                sprintf('%d duplicated lines (%d statements) also found in %s:%d.', $lines, $statements, $relative((string) ($first['name'] ?? '')), (int) ($first['start'] ?? 0)),
                $fragment !== '' ? implode("\n", array_slice(preg_split('/\r\n|\n/', $fragment) ?: [], 0, 6)) : null));
        }

        return $findings;
    }

    /** Fewer meaningful statements than this and a duplicate is boilerplate, not a finding. */
    public const MIN_STATEMENTS = 4;

    /**
     * Lines of a fragment that do something: not blank, not a brace or bracket
     * on its own, not a comment, not an import/use/namespace/declare line, not
     * a class/function/method declaration or a docblock.
     */
    public static function statementsIn(string $fragment): int
    {
        $count = 0;
        foreach (preg_split('/\r\n|\n/', $fragment) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match('~^(?:[{}()\[\];,]+|<\?php|\?>|</?template>|</?script[^>]*>|</?style[^>]*>)$~', $trimmed) === 1) {
                continue;
            }
            if (preg_match('~^(?://|#|/\*|\*|\*/|<!--)~', $trimmed) === 1) {
                continue;
            }
            if (preg_match('~^(?:use |import |export \{|from |namespace |declare\(|require |require_relative |package |@|abstract |final |readonly )~', $trimmed) === 1) {
                continue;
            }
            if (preg_match('~^(?:(?:public|private|protected|static|async|override|abstract|final|readonly|export|default)\s+)*(?:class|interface|trait|enum|function|def|fn|func|struct)\b~', $trimmed) === 1) {
                continue;
            }
            if (preg_match('~^(?:return new class|(?:public|private|protected)\s+(?:static\s+)?function\s+\w+\s*\([^)]*\)\s*(?::\s*[\w?|\\\\]+)?\s*\{?)$~', $trimmed) === 1) {
                continue;
            }
            $count++;
        }

        return $count;
    }
}
