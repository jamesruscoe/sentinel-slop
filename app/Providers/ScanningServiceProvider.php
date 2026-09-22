<?php

namespace App\Providers;

use App\Scanning\Analysers\AnalyserRegistry;
use App\Scanning\Analysers\EslintAnalyser;
use App\Scanning\Analysers\GitleaksAnalyser;
use App\Scanning\Analysers\HeuristicRegistry;
use App\Scanning\Analysers\JscpdAnalyser;
use App\Scanning\Analysers\PhpStanAnalyser;
use App\Scanning\Analysers\PintAnalyser;
use App\Scanning\Analysers\SemgrepAnalyser;
use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\AnalyserOptions;
use App\Scanning\Heuristics\HallucinatedDependenciesHeuristic;
use App\Scanning\Heuristics\NarratingCommentsHeuristic;
use App\Scanning\Heuristics\NearDuplicateFunctionsHeuristic;
use App\Scanning\Heuristics\OversizedUnitsHeuristic;
use App\Scanning\Heuristics\PlaceholderCodeHeuristic;
use App\Scanning\Heuristics\SwallowedExceptionsHeuristic;
use App\Scanning\Preflight\PreflightChecker;
use App\Scanning\Process\ToolLocator;
use App\Services\Scanning\LaravelProcessRunner;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the framework-free scanning core into the container: tool paths and
 * thresholds from config/sentinel.php, the process runner, and which
 * analysers/heuristics run at each pipeline stage.
 */
class ScanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProcessRunner::class, LaravelProcessRunner::class);

        $this->app->bind(ToolLocator::class, fn (): ToolLocator => new ToolLocator((array) config('sentinel.tools', [])));

        $this->app->bind(AnalyserOptions::class, fn (): AnalyserOptions => AnalyserOptions::fromConfig((array) config('sentinel', [])));

        // Preflight: security scanners that run on every repository; hits become critical findings.
        $this->app->bind(PreflightChecker::class, fn (Application $app): PreflightChecker => new PreflightChecker([
            $this->semgrep($app, 'malware', 'semgrep-malware'),
            $app->make(GitleaksAnalyser::class),
        ]));

        // Analysing: tool-based checks for the detected stack.
        $this->app->bind(AnalyserRegistry::class, fn (Application $app): AnalyserRegistry => new AnalyserRegistry([
            $app->make(PhpStanAnalyser::class),
            $app->make(PintAnalyser::class),
            $app->make(EslintAnalyser::class),
            $this->semgrep($app, 'quality', 'semgrep'),
            $app->make(JscpdAnalyser::class),
        ]));

        // Heuristics: AI-slop checks (php-parser based, plus Semgrep slop rules for JS/TS).
        $this->app->bind(HeuristicRegistry::class, function (Application $app): HeuristicRegistry {
            $analysis = (array) config('sentinel.analysis', []);

            return new HeuristicRegistry([
                new NarratingCommentsHeuristic((float) ($analysis['narrating_comment_overlap'] ?? 0.6)),
                new SwallowedExceptionsHeuristic,
                new HallucinatedDependenciesHeuristic,
                new PlaceholderCodeHeuristic,
                new NearDuplicateFunctionsHeuristic((float) ($analysis['near_duplicate_similarity'] ?? 0.85)),
                new OversizedUnitsHeuristic((int) ($analysis['max_file_lines'] ?? 600), (int) ($analysis['max_function_lines'] ?? 80)),
                $this->semgrep($app, 'slop', 'semgrep-slop'),
            ]);
        });
    }

    private function semgrep(Application $app, string $rules, string $name): SemgrepAnalyser
    {
        return new SemgrepAnalyser(
            $app->make(ProcessRunner::class),
            $app->make(ToolLocator::class),
            $app->make(AnalyserOptions::class),
            $rules,
            $name,
        );
    }
}
