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
use PhpParser\PrettyPrinter\Standard;

/**
 * PHP functions whose bodies are the same after renaming variables and
 * literals, or nearly the same by token-shingle similarity. JS/TS clones are
 * reported by jscpd.
 */
final class NearDuplicateFunctionsHeuristic implements Heuristic
{
    private const MIN_STATEMENTS = 5;

    private const MAX_FUNCTIONS = 3000;

    public function __construct(private readonly float $minSimilarity = 0.85) {}

    public function name(): string
    {
        return 'near-duplicate-functions';
    }

    public function supports(Stack $stack): bool
    {
        return $stack->hasPhp();
    }

    public function run(string $path): FindingCollection
    {
        $printer = new Standard;
        /** @var list<array{file: string, line: int, name: string, normalised: string, shingles: array<string, true>}> $functions */
        $functions = [];

        foreach (SourceFiles::in($path, SourceFiles::PHP) as $file) {
            $ast = PhpSource::parse($file['absolute']);
            if ($ast === null) {
                continue;
            }

            foreach ([...PhpSource::find($ast, ClassMethod::class), ...PhpSource::find($ast, Function_::class)] as $function) {
                $statements = $function->getStmts();
                if ($statements === null || count($statements) < self::MIN_STATEMENTS) {
                    continue;
                }

                $normalised = self::normalise($printer->prettyPrint($statements));
                $functions[] = [
                    'file' => $file['path'],
                    'line' => $function->getStartLine(),
                    'name' => $function->name->toString(),
                    'normalised' => $normalised,
                    'shingles' => self::shingles($normalised),
                ];

                if (count($functions) >= self::MAX_FUNCTIONS) {
                    break 2;
                }
            }
        }

        $findings = new FindingCollection;
        $reported = [];

        for ($i = 0; $i < count($functions); $i++) {
            for ($j = $i + 1; $j < count($functions); $j++) {
                $a = $functions[$i];
                $b = $functions[$j];

                $exact = $a['normalised'] === $b['normalised'];
                if (! $exact && ! self::comparableSize($a['shingles'], $b['shingles'])) {
                    continue;
                }

                $similarity = $exact ? 1.0 : self::jaccard($a['shingles'], $b['shingles']);
                if ($similarity < $this->minSimilarity || isset($reported[$j])) {
                    continue;
                }

                $reported[$j] = true;
                $findings->add(new Finding($this->name(), $exact ? 'duplicate-function' : 'near-duplicate-function', FindingCategory::Duplication, Severity::Medium, $b['file'], $b['line'],
                    sprintf('%s() is %s of %s() in %s:%d; extract a shared implementation.', $b['name'], $exact ? 'a duplicate' : sprintf('%d%% similar to', round($similarity * 100)), $a['name'], $a['file'], $a['line'])));
            }
        }

        return $findings;
    }

    private static function normalise(string $code): string
    {
        $code = preg_replace('/\$[A-Za-z_][A-Za-z0-9_]*/', '$v', $code) ?? $code;
        $code = preg_replace('/"[^"]*"|'.chr(39).'[^'.chr(39).']*'.chr(39).'/', 'S', $code) ?? $code;
        $code = preg_replace('/\b\d+(\.\d+)?\b/', 'N', $code) ?? $code;

        return trim(preg_replace('/\s+/', ' ', $code) ?? $code);
    }

    /**
     * @return array<string, true>
     */
    private static function shingles(string $normalised): array
    {
        $tokens = preg_split('/[^A-Za-z0-9_$]+/', $normalised, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $shingles = [];

        for ($i = 0; $i + 4 <= count($tokens); $i++) {
            $shingles[implode(' ', array_slice($tokens, $i, 4))] = true;
        }

        return $shingles;
    }

    /**
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    private static function comparableSize(array $a, array $b): bool
    {
        $min = min(count($a), count($b));
        $max = max(count($a), count($b));

        return $min > 0 && $min / $max >= 0.75;
    }

    /**
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    private static function jaccard(array $a, array $b): float
    {
        $intersection = count(array_intersect_key($a, $b));
        $union = count($a) + count($b) - $intersection;

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
