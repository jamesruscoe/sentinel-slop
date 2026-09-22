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
use PhpParser\Node\Stmt\Function_;

/**
 * TODO/FIXME markers, stubbed PHP functions and hard-coded placeholder data.
 * JS/TS stubs and placeholder data are covered by resources/semgrep/slop.
 */
final class PlaceholderCodeHeuristic implements Heuristic
{
    private const MARKER = '~(^|[^A-Za-z])(TODO|FIXME|XXX|HACK)\b~';

    private const PLACEHOLDER_DATA = '~(lorem ipsum|john\.?doe|jane\.?doe|@example\.com|foo@bar|dummy data|placeholder data|sample data|123-456-7890|555-0100)~i';

    private const MAX_MARKERS_PER_FILE = 20;

    public function name(): string
    {
        return 'placeholder-code';
    }

    public function supports(Stack $stack): bool
    {
        return true;
    }

    public function run(string $path): FindingCollection
    {
        $findings = new FindingCollection;

        foreach (SourceFiles::in($path, [...SourceFiles::PHP, ...SourceFiles::JS, ...SourceFiles::PYTHON]) as $file) {
            $lines = SourceFiles::lines($file['absolute']);
            $markers = 0;
            $isTest = SourceFiles::isTestFile($file['path']);
            $isPhp = in_array(strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)), SourceFiles::PHP, true);

            foreach ($lines as $index => $line) {
                if ($markers < self::MAX_MARKERS_PER_FILE && preg_match(self::MARKER, $line) === 1 && preg_match('~(//|#|/\*|\*)~', $line) === 1) {
                    $findings->add(new Finding($this->name(), 'todo-marker', FindingCategory::Placeholder, Severity::Low, $file['path'], $index + 1,
                        'Unfinished work marker left in code.', trim($line)));
                    $markers++;
                }

                if ($isPhp && ! $isTest && preg_match(self::PLACEHOLDER_DATA, $line) === 1) {
                    $findings->add(new Finding($this->name(), 'placeholder-data', FindingCategory::Placeholder, Severity::Medium, $file['path'], $index + 1,
                        'Hard-coded placeholder data (example names/emails/lorem ipsum) in application code.', trim($line)));
                }
            }

            if ($isPhp) {
                $this->phpStubs($file, $findings);
            }
        }

        return $findings;
    }

    /**
     * @param  array{path: string, absolute: string, size: int}  $file
     */
    private function phpStubs(array $file, FindingCollection $findings): void
    {
        $ast = PhpSource::parse($file['absolute']);
        if ($ast === null) {
            return;
        }

        foreach ([...PhpSource::find($ast, ClassMethod::class), ...PhpSource::find($ast, Function_::class)] as $function) {
            $statements = $function->getStmts();
            if ($statements === null) {
                continue;
            }

            $statements = array_values(array_filter($statements, fn (Node\Stmt $s) => ! $s instanceof Node\Stmt\Nop));
            $name = $function->name->toString();

            if (count($statements) === 1 && $statements[0] instanceof Node\Stmt\Expression && $statements[0]->expr instanceof Node\Expr\Throw_ && $this->isNotImplementedThrow($statements[0]->expr->expr)) {
                $findings->add(new Finding($this->name(), 'not-implemented-stub', FindingCategory::Placeholder, Severity::Medium, $file['path'], $function->getStartLine(),
                    "{$name}() is a stub that throws 'not implemented'."));

                continue;
            }

            if ($statements === [] && $function instanceof ClassMethod && ! $function->isAbstract() && $name !== '__construct' && $function->params === [] && $this->hasTodoComment($function)) {
                $findings->add(new Finding($this->name(), 'empty-stub', FindingCategory::Placeholder, Severity::Low, $file['path'], $function->getStartLine(),
                    "{$name}() has an empty body and a TODO."));
            }
        }
    }

    private function isNotImplementedThrow(Node\Expr $expr): bool
    {
        if (! $expr instanceof Node\Expr\New_ || $expr->args === []) {
            return false;
        }

        $first = $expr->args[0];

        return $first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_
            && preg_match('~not (yet )?implemented|todo|implement me|coming soon~i', $first->value->value) === 1;
    }

    private function hasTodoComment(Node $node): bool
    {
        foreach ($node->getComments() as $comment) {
            if (preg_match(self::MARKER, $comment->getText()) === 1) {
                return true;
            }
        }

        $doc = $node->getDocComment();

        return $doc !== null && preg_match(self::MARKER, $doc->getText()) === 1;
    }
}
