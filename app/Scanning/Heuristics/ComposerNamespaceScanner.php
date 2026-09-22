<?php

declare(strict_types=1);

namespace App\Scanning\Heuristics;

use PhpParser\Node;

/**
 * Finds PHP vendor namespaces that no composer dependency provides.
 */
final class ComposerNamespaceScanner
{
    /** @var array<string, list<string>>  namespace root => composer vendors that provide it */
    private const ALIASES = [
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
     * @return list<array{namespace: string, file: string, line: int}>
     */
    public function scan(string $repoPath, array $composer): array
    {
        $vendors = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys(is_array($composer[$section] ?? null) ? $composer[$section] : []) as $package) {
                $vendors[strtolower(explode('/', (string) $package)[0])] = true;
            }
        }

        $own = array_fill_keys(self::ALWAYS_LOCAL, true);
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (['psr-4', 'psr-0'] as $standard) {
                foreach (array_keys(is_array($composer[$section][$standard] ?? null) ? $composer[$section][$standard] : []) as $namespace) {
                    $own[strtolower(trim(explode(chr(92), (string) $namespace)[0]))] = true;
                }
            }
        }

        /** @var array<string, array{namespace: string, file: string, line: int}> $seen */
        $seen = [];

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

            foreach ($this->referencedNames($ast) as [$name, $line]) {
                if (count($name->getParts()) < 2) {
                    continue;
                }

                $seen[strtolower($name->getFirst())] ??= ['namespace' => $name->getFirst(), 'file' => $file['path'], 'line' => $line];
            }
        }

        $isLaravel = isset($vendors['laravel']);
        $undeclared = [];

        foreach ($seen as $root => $first) {
            if (isset($own[$root])) {
                continue;
            }

            $candidates = self::ALIASES[$root] ?? [$root];
            if (array_intersect($candidates, array_keys($vendors)) !== [] || ($isLaravel && in_array('laravel', $candidates, true))) {
                continue;
            }

            $undeclared[] = $first;
        }

        return $undeclared;
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
            $names[] = [$group->prefix, $group->getStartLine()];
        }

        foreach (PhpSource::find($ast, Node\Name\FullyQualified::class) as $name) {
            $names[] = [$name, $name->getStartLine()];
        }

        return $names;
    }
}
