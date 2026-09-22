<?php

declare(strict_types=1);

namespace App\Scanning\Heuristics;

use App\Scanning\Contracts\Heuristic;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Calls to methods that do not exist on classes whose whole hierarchy is
 * declared in the repository (or is PHP built-in) and has no magic methods.
 * Covers $this->m(), self::m(), static::m(), Repo\Class::m(), typed
 * parameters ($param->m()) and typed properties ($this->prop->m()).
 * Anything reaching an uninstalled dependency (Eloquent models, facades,
 * controllers) is skipped: PHPStan cannot see those parents either.
 */
final class UndefinedMembersHeuristic implements Heuristic
{
    public function name(): string
    {
        return 'undefined-members';
    }

    public function supports(Stack $stack): bool
    {
        return $stack->hasPhp();
    }

    public function run(string $path): FindingCollection
    {
        $index = ClassIndex::build($path);
        $findings = new FindingCollection;

        foreach ($index->asts() as $file => $ast) {
            foreach (PhpSource::find($ast, Node\Stmt\Class_::class) as $class) {
                if ($class->namespacedName === null || $class->isAbstract()) {
                    continue;
                }

                $fqcn = $class->namespacedName->toString();
                $ownMethods = $index->resolvableMethods($fqcn);
                $ownProperties = $index->resolvableProperties($fqcn);

                foreach ($class->getMethods() as $method) {
                    $paramTypes = $this->parameterTypes($method);

                    foreach (PhpSource::find($method->getStmts() ?? [], Node\Expr\MethodCall::class) as $call) {
                        if (! $call->name instanceof Node\Identifier) {
                            continue;
                        }

                        [$targetClass, $targetMethods] = $this->resolveTarget($call->var, $fqcn, $ownMethods, $ownProperties, $paramTypes, $index);
                        if ($targetMethods === null || $targetClass === null || isset($targetMethods[strtolower($call->name->toString())])) {
                            continue;
                        }

                        $findings->add($this->finding($file, $call, $targetClass, $call->name->toString()));
                    }

                    foreach (PhpSource::find($method->getStmts() ?? [], Node\Expr\StaticCall::class) as $call) {
                        if (! $call->name instanceof Node\Identifier || ! $call->class instanceof Node\Name) {
                            continue;
                        }

                        $className = in_array(strtolower($call->class->toString()), ['self', 'static'], true) ? $fqcn : $call->class->toString();
                        if (strtolower($call->class->toString()) === 'parent' || ! $index->has($className)) {
                            continue;
                        }

                        $methods = $index->resolvableMethods($className);
                        if ($methods === null || isset($methods[strtolower($call->name->toString())])) {
                            continue;
                        }

                        $findings->add($this->finding($file, $call, $className, $call->name->toString(), static: true));
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, true>|null  $ownMethods
     * @param  array<string, string|null>|null  $ownProperties
     * @param  array<string, string>  $paramTypes
     * @return array{0: string|null, 1: array<string, true>|null}
     */
    private function resolveTarget(Node\Expr $var, string $fqcn, ?array $ownMethods, ?array $ownProperties, array $paramTypes, ClassIndex $index): array
    {
        if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
            if ($var->name === 'this') {
                return [$fqcn, $ownMethods];
            }

            $type = $paramTypes[$var->name] ?? null;

            return $type !== null && $index->has($type) ? [$type, $index->resolvableMethods($type)] : [null, null];
        }

        if ($var instanceof Node\Expr\PropertyFetch && $var->var instanceof Node\Expr\Variable && $var->var->name === 'this' && $var->name instanceof Node\Identifier) {
            $type = $ownProperties[$var->name->toString()] ?? null;

            return $type !== null && $index->has($type) ? [$type, $index->resolvableMethods($type)] : [null, null];
        }

        return [null, null];
    }

    /**
     * @return array<string, string> Parameter name => class type FQCN.
     */
    private function parameterTypes(ClassMethod $method): array
    {
        $types = [];
        foreach ($method->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $type = ClassIndex::classType($param->type);
                if ($type !== null) {
                    $types[$param->var->name] = $type;
                }
            }
        }

        return $types;
    }

    private function finding(string $file, Node $call, string $class, string $method, bool $static = false): Finding
    {
        return new Finding($this->name(), 'undefined-method', FindingCategory::TypeSafety, Severity::High, $file, $call->getStartLine(),
            sprintf('Call to undefined method %s%s%s(); the class and its whole hierarchy are in this repository and declare no such method.', $class, $static ? '::' : '->', $method));
    }
}
