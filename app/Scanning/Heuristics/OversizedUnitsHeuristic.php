<?php

declare(strict_types=1);

namespace App\Scanning\Heuristics;

use App\Scanning\Contracts\Heuristic;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Files and PHP functions that are too long. JS/TS function length comes from
 * ESLint's max-lines-per-function in the bundled config.
 */
final class OversizedUnitsHeuristic implements Heuristic
{
    public function __construct(private readonly int $maxFileLines = 600, private readonly int $maxFunctionLines = 80) {}

    public function name(): string
    {
        return 'oversized-units';
    }

    public function supports(Stack $stack): bool
    {
        return true;
    }

    public function run(string $path): FindingCollection
    {
        $findings = new FindingCollection;

        foreach (SourceFiles::in($path, [...SourceFiles::PHP, ...SourceFiles::JS, ...SourceFiles::PYTHON]) as $file) {
            // Seeders, factories, migrations, config and fixtures are data: a 140-line seeder is a list, not a unit to split.
            if (preg_match('~(^|/)(database|config|fixtures?|seeds?|seeders?|factories|migrations|lang|locales?)/~i', $file['path']) === 1) {
                continue;
            }
            $codeLines = count(array_filter(SourceFiles::lines($file['absolute']), fn (string $l) => trim($l) !== ''));

            if ($codeLines > $this->maxFileLines) {
                $findings->add(new Finding($this->name(), 'oversized-file', FindingCategory::Complexity, Severity::Medium, $file['path'], null,
                    "File has {$codeLines} non-blank lines (limit {$this->maxFileLines}); split it by responsibility."));
            }

            if (! in_array(SourceFiles::extensionOf($file['path']), SourceFiles::PHP, true)) {
                continue;
            }

            $ast = PhpSource::parse($file['absolute']);
            if ($ast === null) {
                continue;
            }

            foreach ([...PhpSource::find($ast, ClassMethod::class), ...PhpSource::find($ast, Function_::class)] as $function) {
                $length = $function->getEndLine() - $function->getStartLine() + 1;
                if ($length > $this->maxFunctionLines) {
                    $findings->add(new Finding($this->name(), 'oversized-function', FindingCategory::Complexity, Severity::Medium, $file['path'], $function->getStartLine(),
                        sprintf('%s() is %d lines long (limit %d); extract smaller units.', $function->name->toString(), $length, $this->maxFunctionLines)));
                }
            }
        }

        return $findings;
    }
}
