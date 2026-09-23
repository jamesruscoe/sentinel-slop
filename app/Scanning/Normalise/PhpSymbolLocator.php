<?php

declare(strict_types=1);

namespace App\Scanning\Normalise;

use App\Scanning\Heuristics\PhpSource;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

/**
 * Resolves the enclosing class and method/function for a line in a PHP file
 * from its AST. Returns null whenever the AST cannot answer (unparsable
 * file, line outside any declaration, anonymous class, non-PHP file): a
 * missing symbol is always better than a wrong one.
 */
final class PhpSymbolLocator
{
    /** @var array<string, list<array{start: int, end: int, symbol: string}>> */
    private array $index = [];

    public function __construct(private readonly string $repoPath) {}

    public function locate(string $relativePath, ?int $line): ?string
    {
        $lower = strtolower($relativePath);
        if ($line === null || $line < 1 || ! str_ends_with($lower, '.php') || str_ends_with($lower, '.blade.php')) {
            return null;
        }

        $best = null;
        foreach ($this->index[$relativePath] ??= $this->build($relativePath) as $range) {
            if ($range['start'] <= $line && $line <= $range['end'] && ($best === null || $range['end'] - $range['start'] < $best['end'] - $best['start'])) {
                $best = $range;
            }
        }

        return $best['symbol'] ?? null;
    }

    /**
     * @return list<array{start: int, end: int, symbol: string}>
     */
    private function build(string $relativePath): array
    {
        $ast = PhpSource::parse(rtrim($this->repoPath, '/').'/'.ltrim($relativePath, '/'));
        if ($ast === null) {
            return [];
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast);

        $ranges = [];

        foreach (PhpSource::find($ast, Node\Stmt\ClassLike::class) as $class) {
            if ($class->namespacedName === null) {
                continue;
            }

            $fqcn = $class->namespacedName->toString();
            $ranges[] = ['start' => $class->getStartLine(), 'end' => $class->getEndLine(), 'symbol' => $fqcn];

            foreach ($class->getMethods() as $method) {
                $ranges[] = ['start' => $method->getStartLine(), 'end' => $method->getEndLine(), 'symbol' => $fqcn.'::'.$method->name->toString().'()'];
            }
        }

        foreach (PhpSource::find($ast, Node\Stmt\Function_::class) as $function) {
            if ($function->namespacedName !== null) {
                $ranges[] = ['start' => $function->getStartLine(), 'end' => $function->getEndLine(), 'symbol' => $function->namespacedName->toString().'()'];
            }
        }

        return $ranges;
    }
}
