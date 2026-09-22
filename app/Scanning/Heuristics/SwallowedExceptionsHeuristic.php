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
use PhpParser\Node\Stmt\Catch_;

/**
 * PHP catch blocks that swallow the exception (empty body) or only log it and
 * carry on. The JS/TS equivalent lives in resources/semgrep/slop.
 */
final class SwallowedExceptionsHeuristic implements Heuristic
{
    private const LOG_FUNCTIONS = ['logger', 'report', 'error_log', 'info', 'dump', 'var_dump', 'print_r', 'ray', 'dd'];

    private const LOG_CLASSES = ['log', 'logger', 'sentry', 'bugsnag'];

    private const LOG_METHODS = ['info', 'error', 'warning', 'warn', 'debug', 'log', 'critical', 'notice', 'alert', 'emergency', 'capture', 'captureexception', 'report'];

    public function name(): string
    {
        return 'swallowed-exceptions';
    }

    public function supports(Stack $stack): bool
    {
        return $stack->hasPhp();
    }

    public function run(string $path): FindingCollection
    {
        $findings = new FindingCollection;

        foreach (SourceFiles::in($path, SourceFiles::PHP) as $file) {
            $ast = PhpSource::parse($file['absolute']);
            if ($ast === null) {
                continue;
            }

            foreach (PhpSource::find($ast, Catch_::class) as $catch) {
                $statements = array_values(array_filter($catch->stmts, fn (Node\Stmt $s) => ! $s instanceof Node\Stmt\Nop));

                if ($statements === []) {
                    $findings->add(new Finding($this->name(), 'empty-catch', FindingCategory::ErrorHandling, Severity::Medium, $file['path'], $catch->getStartLine(),
                        'Empty catch block swallows the exception; handle it, rethrow it, or document why it is safe to ignore.'));
                } elseif ($this->onlyLogsAndContinues($statements)) {
                    $findings->add(new Finding($this->name(), 'catch-only-logs', FindingCategory::ErrorHandling, Severity::Medium, $file['path'], $catch->getStartLine(),
                        'Catch block only logs and continues; callers never learn the operation failed.'));
                }
            }
        }

        return $findings;
    }

    /**
     * @param  list<Node\Stmt>  $statements
     */
    private function onlyLogsAndContinues(array $statements): bool
    {
        $sawLog = false;

        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Return_) {
                if ($statement->expr === null || $this->isEmptyValue($statement->expr)) {
                    continue;
                }

                return false;
            }

            if ($statement instanceof Node\Stmt\Echo_ || ($statement instanceof Node\Stmt\Expression && $this->isLogCall($statement->expr))) {
                $sawLog = true;

                continue;
            }

            return false;
        }

        return $sawLog;
    }

    private function isLogCall(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            return in_array(strtolower($expr->name->toString()), self::LOG_FUNCTIONS, true);
        }

        if ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name) {
            return in_array(strtolower($expr->class->getLast()), self::LOG_CLASSES, true);
        }

        if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            $target = $expr->var;
            $onLogger = ($target instanceof Node\Expr\PropertyFetch && $target->name instanceof Node\Identifier && str_contains(strtolower($target->name->toString()), 'log'))
                || ($target instanceof Node\Expr\Variable && is_string($target->name) && str_contains(strtolower($target->name), 'log'))
                || ($target instanceof Node\Expr\FuncCall && $target->name instanceof Node\Name && strtolower($target->name->toString()) === 'logger');

            return $onLogger && in_array(strtolower($expr->name->toString()), self::LOG_METHODS, true);
        }

        return false;
    }

    private function isEmptyValue(Node\Expr $expr): bool
    {
        return ($expr instanceof Node\Expr\ConstFetch && in_array(strtolower($expr->name->toString()), ['null', 'false', 'true'], true))
            || ($expr instanceof Node\Expr\Array_ && $expr->items === [])
            || ($expr instanceof Node\Scalar\String_ && $expr->value === '')
            || ($expr instanceof Node\Scalar\Int_ && $expr->value === 0)
            || ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name && strtolower($expr->class->getLast()) === 'collection');
    }
}
