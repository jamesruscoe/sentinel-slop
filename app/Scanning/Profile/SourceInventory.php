<?php

declare(strict_types=1);

namespace App\Scanning\Profile;

use App\Scanning\Detect\DependencyIndex;
use App\Scanning\Heuristics\PhpSource;
use App\Scanning\Heuristics\SourceFiles;
use App\Scanning\Support\FileWalker;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

/**
 * One pass over every file in the repository: what it is (area, kind,
 * family, stem, test or not), how big it is, what it references, and how
 * many of each profile signal it contains. Files are read once; nothing is
 * executed. PHP files are parsed (never evaluated) so that references are
 * fully qualified names, not guesses at what an alias means.
 *
 * @phpstan-type FileFact array{
 *   path: string, family: string|null, area: string, kind: string, stem: string|null, is_test: bool, is_template: bool, is_config_path: bool,
 *   lines: int, code_lines: int, comment_lines: int, depth: int,
 *   references: list<string>, imports: list<string>, declares: list<string>, mentions: list<string>,
 *   signals: array<string, int>,
 *   functions: list<array{name: string, line: int, length: int}>
 * }
 */
final class SourceInventory
{
    private const MAX_FILE_BYTES = 1024 * 1024;

    public const SIGNALS = ['logging_call', 'try', 'catch', 'input_read', 'validation_call', 'validation_mechanism', 'env_read', 'config_read', 'external_call', 'guard', 'global_handler'];

    /**
     * @param  list<FileFact>  $files
     */
    private function __construct(public readonly array $files) {}

    public static function build(string $repoPath): self
    {
        $entries = array_values(array_filter(FileWalker::walk($repoPath), fn (array $e) => ! $e['is_link'] && $e['size'] <= self::MAX_FILE_BYTES));
        $containers = self::containers(array_map(fn (array $e) => $e['path'], $entries));

        $facts = [];
        foreach ($entries as $entry) {
            $facts[] = self::inspect($entry['path'], $entry['absolute'], $containers);
        }

        return new self($facts);
    }

    /**
     * Directories that are containers of areas rather than areas themselves,
     * discovered from the tree: a Python package whose subdirectories are
     * packages too (hc/, hc/integrations/), a workspace package with its own
     * package.json or composer.json (cli/, www/, packages/foo/).
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function containers(array $paths): array
    {
        $packageDirs = [];
        $manifestDirs = [];
        foreach ($paths as $path) {
            $dir = dirname($path);
            $base = basename($path);
            if ($base === '__init__.py') {
                $packageDirs[$dir === '.' ? '' : $dir] = true;
            } elseif (($base === 'package.json' || $base === 'composer.json') && $dir !== '.') {
                $manifestDirs[$dir] = true;
            }
        }

        $containers = [];
        foreach (array_keys($packageDirs) as $dir) {
            if ($dir === '' || substr_count($dir, '/') > 1) {
                continue;
            }
            foreach (array_keys($packageDirs) as $other) {
                if ($other !== $dir && str_starts_with($other, $dir.'/')) {
                    $containers[$dir] = true;

                    break;
                }
            }
        }
        foreach (array_keys($manifestDirs) as $dir) {
            if (substr_count($dir, '/') <= 1 && ! str_contains($dir, 'node_modules')) {
                $containers[$dir] = true;
            }
        }

        return array_keys($containers);
    }

    /**
     * @return list<FileFact>
     */
    public function source(): array
    {
        return array_values(array_filter($this->files, fn (array $f) => $f['family'] !== null));
    }

    /**
     * @param  list<string>  $containers
     * @return FileFact
     */
    private static function inspect(string $path, string $absolute, array $containers = []): array
    {
        $family = LanguagePatterns::familyOf($path);
        $isTest = Naming::isTestName($path);
        $fact = [
            'path' => $path,
            'family' => $family,
            'area' => Naming::areaOf($path, $containers),
            'kind' => Naming::kindOf($path),
            'stem' => Naming::stemOf($path),
            'is_test' => $isTest,
            'is_template' => SourceFiles::isTemplateFile($path),
            'is_config_path' => Naming::isConfigPath($path),
            'lines' => 0,
            'code_lines' => 0,
            'comment_lines' => 0,
            'depth' => substr_count($path, '/'),
            'references' => [],
            'imports' => [],
            'declares' => [],
            'mentions' => [],
            'signals' => array_fill_keys(self::SIGNALS, 0),
            'functions' => [],
        ];

        // Lockfiles are machine output: their tens of thousands of lines say nothing about the codebase.
        if (DependencyIndex::isLockfile($path)) {
            $fact['kind'] = 'config';

            return $fact;
        }

        $textual = $family !== null
            || str_ends_with(strtolower($path), '.blade.php')
            || in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['md', 'mdx', 'html', 'twig', 'erb', 'json', 'yml', 'yaml', 'toml', 'css', 'scss', 'sql', 'sh', 'tf', 'xml', 'txt', 'ini', 'neon'], true);
        if (! $textual) {
            return $fact;
        }

        $contents = @file_get_contents($absolute);
        if ($contents === false) {
            return $fact;
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $fact['lines'] = count($lines);
        $commentFamily = $family ?? 'js';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (LanguagePatterns::isCommentLine($commentFamily, $trimmed)) {
                $fact['comment_lines']++;

                continue;
            }
            $fact['code_lines']++;
        }

        $fact['mentions'] = self::mentionsIn($contents);

        if ($family === null) {
            return $fact;
        }

        foreach (self::SIGNALS as $signal) {
            $fact['signals'][$signal] = LanguagePatterns::count($family, $signal, $contents);
        }

        if ($family === 'php') {
            self::inspectPhp($absolute, $fact);
        } else {
            $fact['imports'] = self::importsOf($family, $contents);
            $fact['functions'] = self::functionsOf($family, $lines);
        }

        return $fact;
    }

    /**
     * @param  FileFact  $fact
     */
    private static function inspectPhp(string $absolute, array &$fact): void
    {
        $ast = PhpSource::parse($absolute);
        if ($ast === null) {
            return;
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => false, 'replaceNodes' => true]));
        $resolved = $traverser->traverse($ast);

        $references = [];
        foreach (PhpSource::find($resolved, Node\Name\FullyQualified::class) as $name) {
            $references[$name->toString()] = true;
        }
        foreach (PhpSource::find($resolved, Node\Stmt\Class_::class) as $class) {
            if ($class->namespacedName !== null) {
                $fact['declares'][] = $class->namespacedName->toString();
            }
        }
        foreach ([Node\Stmt\Interface_::class, Node\Stmt\Trait_::class, Node\Stmt\Enum_::class] as $type) {
            foreach (PhpSource::find($resolved, $type) as $declaration) {
                if ($declaration->namespacedName !== null) {
                    $fact['declares'][] = $declaration->namespacedName->toString();
                }
            }
        }
        $fact['references'] = array_keys($references);
        $fact['imports'] = $fact['references'];

        foreach ([...PhpSource::find($resolved, ClassMethod::class), ...PhpSource::find($resolved, Function_::class)] as $function) {
            $fact['functions'][] = ['name' => $function->name->toString(), 'line' => $function->getStartLine(), 'length' => $function->getEndLine() - $function->getStartLine() + 1];
        }
    }

    /**
     * Quoted strings that may name another repository file: a route file in
     * bootstrap/app.php, an Inertia page in a controller, a Blade view, a
     * composer autoload file, a Vite input. ImportGraph resolves them; only
     * those that resolve become edges.
     *
     * @return list<string>
     */
    private static function mentionsIn(string $contents): array
    {
        // Leading "/" and "." are allowed: `require __DIR__.'/auth.php'` mentions "/auth.php", imports mention "./x".
        // A file with no plain path strings can still hold globs or dynamic-import prefixes, so nothing returns early.
        preg_match_all('~[\'"]([A-Za-z0-9_@./][A-Za-z0-9_.\-/]{2,200})[\'"]~', $contents, $m);

        $mentions = [];
        // Glob patterns (import.meta.glob('./pages/**/*.vue'), require.context) reference whole directories.
        if (preg_match_all('%[\'"]((?:\./|\.\./|@/|~/|/)?[A-Za-z0-9_.\-/]*\*[A-Za-z0-9_.\-/*{},]*)[\'"]%', $contents, $g) > 0) {
            foreach ($g[1] as $glob) {
                $mentions['glob:'.$glob] = true;
            }
        }
        // Dynamic imports built from a prefix and a variable (import_module(f"hc.integrations.{kind}.transport"),
        // import(`./locales/${lang}.ts`)): everything under the prefix may be loaded, so it becomes a glob.
        if (preg_match_all('%[\'"`]((?:\./|\.\./|@/|~/)?[A-Za-z_][A-Za-z0-9_.\-/]*[./])(?:\{|\$\{)%', $contents, $dyn) > 0) {
            foreach ($dyn[1] as $prefix) {
                $mentions['glob:'.$prefix.'*'] = true;
            }
        }
        foreach ($m[1] as $candidate) {
            if (! str_contains($candidate, '/') && ! str_contains($candidate, '.')) {
                continue;
            }
            if (str_starts_with($candidate, 'http') || str_contains($candidate, '//') || preg_match('/^[\d.]+$/', $candidate) === 1) {
                continue;
            }
            $mentions[$candidate] = true;
            if (count($mentions) >= 300) {
                break;
            }
        }

        return array_keys($mentions);
    }

    /**
     * Raw import specifiers for non-PHP families (module paths or package names).
     *
     * @return list<string>
     */
    private static function importsOf(string $family, string $contents): array
    {
        $imports = [];
        $pattern = match ($family) {
            'js' => '/(?:\bimport\s+(?:[^\'";]*?\s+from\s+)?|\bexport\s+(?:\*|\{[^}]*\})\s+from\s+|\brequire\(\s*|\bimport\(\s*)[\'"]([^\'"]+)[\'"]/',
            'python' => '/^\s*(?:from\s+([\w.]+)\s+import\s+\(?([\w.,\s*]+?)\)?\s*(?:#.*)?$|import\s+([\w.]+(?:\s*,\s*[\w.]+)*))/m',
            'ruby' => '/^\s*require(?:_relative)?\s+[\'"]([^\'"]+)[\'"]/m',
            'go' => '/^\s*(?:import\s+)?"([^"]+)"\s*$/m',
            'java' => '/^\s*import\s+(?:static\s+)?([\w.]+)/m',
            'csharp' => '/^\s*using\s+([\w.]+)\s*;/m',
            'rust' => '/^\s*use\s+([\w:]+)/m',
            default => null,
        };
        if ($pattern === null) {
            return [];
        }

        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        foreach ($matches as $match) {
            if ($family === 'python') {
                // `from hc.api import views, models` names the modules hc.api.views and hc.api.models as well as the package.
                $module = $match[1] ?? '';
                $names = $match[2] ?? '';
                $plain = $match[3] ?? '';
                if ($module !== '') {
                    $imports[$module] = true;
                    foreach (explode(',', $names) as $name) {
                        $name = trim(preg_replace('/\s+as\s+\w+$/', '', trim($name)) ?? '');
                        if ($name !== '' && $name !== '*' && preg_match('/^\w+$/', $name) === 1) {
                            $imports[rtrim($module, '.').(str_ends_with($module, '.') ? '' : '.').$name] = true;
                        }
                    }
                }
                foreach (explode(',', $plain) as $part) {
                    $part = trim(preg_replace('/\s+as\s+\w+$/', '', trim($part)) ?? '');
                    if ($part !== '') {
                        $imports[$part] = true;
                    }
                }

                continue;
            }
            foreach (array_slice($match, 1) as $value) {
                foreach (explode(',', $value) as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $imports[$part] = true;
                    }
                }
            }
        }

        return array_keys($imports);
    }

    /**
     * Function sizes for non-PHP families: brace matching for C-like syntax,
     * indentation for Python. Approximate by design; the profile says so.
     *
     * @param  list<string>  $lines
     * @return list<array{name: string, line: int, length: int}>
     */
    private static function functionsOf(string $family, array $lines): array
    {
        if ($family === 'python') {
            return self::pythonFunctions($lines);
        }
        if (! in_array($family, ['js', 'go', 'java', 'csharp', 'rust', 'swift'], true)) {
            return [];
        }

        $functions = [];
        $count = count($lines);
        $declaration = '/(?:\bfunction\s+([A-Za-z_$][\w$]*)\s*\(|\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:\([^)]*\)|[A-Za-z_$][\w$]*)\s*=>|\bfunc\s+(?:\([^)]*\)\s*)?([A-Za-z_]\w*)\s*\(|^\s*(?:(?:public|private|protected|static|async|override|internal|fn|pub)\s+)*(?:[\w<>\[\],\s]+\s+)?([A-Za-z_]\w*)\s*\([^;{}]*\)\s*(?::\s*[\w<>\[\]|,\s]+)?\s*(?:->\s*[\w<>\[\]&\']+\s*)?\{\s*$)/';

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match($declaration, $line, $m) !== 1) {
                continue;
            }
            $name = '';
            foreach (array_slice($m, 1) as $group) {
                if ($group !== '') {
                    $name = $group;

                    break;
                }
            }
            if ($name === '' || in_array($name, ['if', 'for', 'while', 'switch', 'catch', 'function', 'return', 'else', 'try'], true)) {
                continue;
            }

            $depth = 0;
            $opened = false;
            for ($j = $i; $j < $count && $j < $i + 2000; $j++) {
                $depth += substr_count($lines[$j], '{') - substr_count($lines[$j], '}');
                if (str_contains($lines[$j], '{')) {
                    $opened = true;
                }
                if ($opened && $depth <= 0) {
                    $functions[] = ['name' => $name, 'line' => $i + 1, 'length' => $j - $i + 1];

                    break;
                }
            }
        }

        return $functions;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{name: string, line: int, length: int}>
     */
    private static function pythonFunctions(array $lines): array
    {
        $functions = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (preg_match('/^(\s*)(?:async\s+)?def\s+(\w+)\s*\(/', $lines[$i], $m) !== 1) {
                continue;
            }
            $indent = strlen($m[1]);
            $end = $i;
            for ($j = $i + 1; $j < $count; $j++) {
                $line = $lines[$j];
                if (trim($line) === '' || preg_match('/^\s*#/', $line) === 1) {
                    continue;
                }
                if (strlen($line) - strlen(ltrim($line)) <= $indent) {
                    break;
                }
                $end = $j;
            }
            $functions[] = ['name' => $m[2], 'line' => $i + 1, 'length' => $end - $i + 1];
        }

        return $functions;
    }
}
