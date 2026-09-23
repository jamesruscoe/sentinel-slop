<?php

declare(strict_types=1);

namespace App\Scanning\Heuristics;

use PhpParser\Node;

/**
 * Collects every fully qualified vendor name a PHP codebase references
 * (use statements, group uses, fully qualified names), excluding the
 * repository's own namespaces. Resolution against lockfiles happens in
 * HallucinatedDependenciesHeuristic; this class only finds candidates.
 */
final class ComposerNamespaceScanner
{
    /**
     * Manifest-only fallback when there is no composer.lock: namespace root => vendors that ship it.
     *
     * @var array<string, list<string>>
     */
    public const ALIASES = [
        'illuminate' => ['laravel', 'illuminate'],
        'carbon' => ['nesbot', 'laravel'],
        'symfony' => ['symfony', 'laravel'],
        'guzzlehttp' => ['guzzlehttp', 'laravel'],
        'monolog' => ['monolog', 'laravel'],
        'league' => ['league', 'laravel'],
        'ramsey' => ['ramsey', 'laravel'],
        'doctrine' => ['doctrine', 'laravel'],
        'faker' => ['fakerphp', 'laravel'],
        'mockery' => ['mockery', 'laravel'],
        'phpunit' => ['phpunit', 'pestphp'],
        'pest' => ['pestphp'],
        'nunomaduro' => ['nunomaduro', 'laravel'],
        'whoops' => ['filp', 'laravel'],
        'brick' => ['brick', 'laravel'],
        'psr' => ['psr', 'laravel'],
    ];

    private const ALWAYS_LOCAL = ['app', 'tests', 'database', 'domain', 'modules', 'src'];

    /**
     * @param  array<string, mixed>  $composer  Decoded composer.json.
     * @return list<array{name: string, file: string, line: int}> Unique fully qualified names, first occurrence each.
     */
    public function scan(string $repoPath, array $composer): array
    {
        $own = array_fill_keys(self::ALWAYS_LOCAL, true);
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (['psr-4', 'psr-0'] as $standard) {
                foreach (array_keys(is_array($composer[$section][$standard] ?? null) ? $composer[$section][$standard] : []) as $namespace) {
                    $own[strtolower(trim(explode(chr(92), (string) $namespace)[0]))] = true;
                }
            }
        }

        /** @var list<array{0: list<array{0: Node\Name, 1: int}>, 1: string}> $perFile */
        $perFile = [];

        foreach (SourceFiles::in($repoPath, SourceFiles::PHP) as $file) {
            $ast = PhpSource::parse($file['absolute']);
            if ($ast === null) {
                continue;
            }

            foreach (PhpSource::find($ast, Node\Stmt\Namespace_::class) as $namespace) {
                if ($namespace->name !== null) {
                    $own[strtolower($namespace->name->getFirst())] = true;
                }
            }

            $perFile[] = [$this->referencedNames($ast), $file['path']];
        }

        /** @var array<string, array{name: string, file: string, line: int}> $seen */
        $seen = [];

        foreach ($perFile as [$names, $path]) {
            foreach ($names as [$name, $line]) {
                if (count($name->getParts()) < 2 || isset($own[strtolower($name->getFirst())])) {
                    continue;
                }

                $seen[strtolower($name->toString())] ??= ['name' => $name->toString(), 'file' => $path, 'line' => $line];
            }
        }

        return array_values($seen);
    }

    /**
     * @param  array<Node>  $ast
     * @return list<array{0: Node\Name, 1: int}>
     */
    private function referencedNames(array $ast): array
    {
        $names = [];

        foreach (PhpSource::find($ast, Node\Stmt\Use_::class) as $use) {
            foreach ($use->uses as $item) {
                $names[] = [$item->name, $use->getStartLine()];
            }
        }

        foreach (PhpSource::find($ast, Node\Stmt\GroupUse::class) as $group) {
            foreach ($group->uses as $item) {
                $names[] = [Node\Name::concat($group->prefix, $item->name) ?? $group->prefix, $group->getStartLine()];
            }
        }

        foreach (PhpSource::find($ast, Node\Name\FullyQualified::class) as $name) {
            $names[] = [$name, $name->getStartLine()];
        }

        return $names;
    }
}
