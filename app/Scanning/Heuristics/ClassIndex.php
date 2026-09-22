<?php

declare(strict_types=1);

namespace App\Scanning\Heuristics;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

/**
 * Every class, interface, trait and enum declared in the repository with its
 * members and relations, resolved to fully qualified names. Built by parsing
 * only; nothing is autoloaded or executed.
 */
final class ClassIndex
{
    private const MAGIC_CALL = ['__call', '__callstatic', '__get', '__set'];

    /** @var array<string, array{kind: string, parent: string|null, interfaces: list<string>, traits: list<string>, methods: array<string, true>, properties: array<string, string|null>, abstract: bool, magic: bool}> */
    private array $classes = [];

    /** @var array<string, array<Node>> */
    private array $resolvedAsts = [];

    public static function build(string $repoPath): self
    {
        $index = new self;

        foreach (SourceFiles::in($repoPath, SourceFiles::PHP) as $file) {
            $ast = PhpSource::parse($file['absolute']);
            if ($ast === null) {
                continue;
            }

            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $resolved = $traverser->traverse($ast);
            $index->resolvedAsts[$file['path']] = $resolved;

            foreach (PhpSource::find($resolved, Node\Stmt\ClassLike::class) as $classLike) {
                $index->register($classLike);
            }
        }

        return $index;
    }

    /**
     * @return array<string, array<Node>> Relative path => name-resolved AST.
     */
    public function asts(): array
    {
        return $this->resolvedAsts;
    }

    public function has(string $fqcn): bool
    {
        return isset($this->classes[strtolower($fqcn)]);
    }

    public function isAbstract(string $fqcn): bool
    {
        return $this->classes[strtolower($fqcn)]['abstract'] ?? false;
    }

    /**
     * All method names available on a class through its whole hierarchy, or
     * null when any ancestor, trait or interface is not in the index (or not a
     * PHP built-in) or when magic methods could answer any call.
     *
     * @param  array<string, true>  $seen
     * @return array<string, true>|null
     */
    public function resolvableMethods(string $fqcn, array $seen = []): ?array
    {
        $key = strtolower($fqcn);

        if (isset($seen[$key])) {
            return [];
        }
        $seen[$key] = true;

        $entry = $this->classes[$key] ?? null;

        if ($entry === null) {
            return self::builtinMethods($fqcn);
        }

        if ($entry['magic']) {
            return null;
        }

        $methods = $entry['methods'];
        if ($entry['kind'] === 'enum') {
            $methods += ['cases' => true, 'from' => true, 'tryfrom' => true];
        }

        foreach (array_filter([$entry['parent'], ...$entry['interfaces'], ...$entry['traits']]) as $related) {
            $inherited = $this->resolvableMethods($related, $seen);
            if ($inherited === null) {
                return null;
            }
            $methods += $inherited;
        }

        return $methods;
    }

    /**
     * Declared property name => class type (FQCN) or null when untyped/scalar.
     *
     * @param  array<string, true>  $seen
     * @return array<string, string|null>|null
     */
    public function resolvableProperties(string $fqcn, array $seen = []): ?array
    {
        $key = strtolower($fqcn);
        if (isset($seen[$key])) {
            return [];
        }
        $seen[$key] = true;

        $entry = $this->classes[$key] ?? null;
        if ($entry === null) {
            return self::builtinMethods($fqcn) === null ? null : [];
        }
        if ($entry['magic']) {
            return null;
        }

        $properties = $entry['properties'];
        foreach (array_filter([$entry['parent'], ...$entry['traits']]) as $related) {
            $inherited = $this->resolvableProperties($related, $seen);
            if ($inherited === null) {
                return null;
            }
            $properties += $inherited;
        }

        return $properties;
    }

    private function register(Node\Stmt\ClassLike $node): void
    {
        if ($node->namespacedName === null) {
            return;
        }

        $methods = [];
        $properties = [];
        $magic = false;

        foreach ($node->getMethods() as $method) {
            $name = strtolower($method->name->toString());
            $methods[$name] = true;
            if (in_array($name, self::MAGIC_CALL, true)) {
                $magic = true;
            }

            if ($name === '__construct') {
                foreach ($method->params as $param) {
                    if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                        $properties[$param->var->name] = self::classType($param->type);
                    }
                }
            }
        }

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $properties[$prop->name->toString()] = self::classType($stmt->type);
                }
            }
        }

        $traits = [];
        foreach ($node->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $traits[] = $trait->toString();
            }
        }

        [$kind, $parent, $interfaces, $abstract] = match (true) {
            $node instanceof Node\Stmt\Class_ => ['class', $node->extends?->toString(), array_map(fn (Node\Name $n) => $n->toString(), $node->implements), $node->isAbstract()],
            $node instanceof Node\Stmt\Interface_ => ['interface', null, array_map(fn (Node\Name $n) => $n->toString(), $node->extends), true],
            $node instanceof Node\Stmt\Enum_ => ['enum', null, array_map(fn (Node\Name $n) => $n->toString(), $node->implements), false],
            default => ['trait', null, [], true],
        };

        $this->classes[strtolower($node->namespacedName->toString())] = [
            'kind' => $kind, 'parent' => $parent, 'interfaces' => $interfaces, 'traits' => $traits,
            'methods' => $methods, 'properties' => $properties, 'abstract' => $abstract, 'magic' => $magic,
        ];
    }

    public static function classType(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            return self::classType($type->type);
        }

        return $type instanceof Node\Name ? $type->toString() : null;
    }

    /**
     * Methods of a PHP built-in class (never autoloads: only classes already
     * present in the engine count).
     *
     * @return array<string, true>|null
     */
    private static function builtinMethods(string $fqcn): ?array
    {
        $name = ltrim($fqcn, chr(92));

        if (! class_exists($name, false) && ! interface_exists($name, false)) {
            return null;
        }

        $reflection = new \ReflectionClass($name);
        if (! $reflection->isInternal()) {
            return null;
        }

        $methods = [];
        foreach ($reflection->getMethods() as $method) {
            $methods[strtolower($method->getName())] = true;
        }

        return $methods;
    }
}
