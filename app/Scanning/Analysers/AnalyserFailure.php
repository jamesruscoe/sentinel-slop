<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Exceptions\AnalyserTimedOutException;
use Throwable;

/**
 * One analyser or heuristic that did not complete. The scan carries on
 * without it; the results page and the reviewer are told which tool is
 * missing and why, so its silence is never read as a clean result.
 */
final class AnalyserFailure
{
    public function __construct(
        public readonly string $tool,
        public readonly string $kind,
        public readonly string $message,
        public readonly int $seconds,
    ) {}

    public static function from(string $tool, Throwable $exception, float $elapsed): self
    {
        return new self(
            $tool,
            $exception instanceof AnalyserTimedOutException ? 'timeout' : 'error',
            mb_substr($exception->getMessage(), 0, 300),
            // A timeout reports its limit (that is what the reader needs), a crash how long it ran.
            $exception instanceof AnalyserTimedOutException ? $exception->seconds : (int) round($elapsed),
        );
    }

    /**
     * How the failure reads in the coverage line: "PHPStan timed out after 900 s and did not run".
     */
    public function sentence(): string
    {
        $name = self::displayName($this->tool);

        return $this->kind === 'timeout'
            ? "{$name} timed out after {$this->seconds} s and did not run"
            : "{$name} failed and did not run ({$this->message})";
    }

    public static function displayName(string $tool): string
    {
        return match ($tool) {
            'phpstan' => 'PHPStan',
            'pint' => 'Pint',
            'eslint' => 'ESLint',
            'ruff' => 'Ruff',
            'semgrep' => 'Semgrep quality rules',
            'semgrep-slop' => 'Semgrep slop rules',
            'semgrep-malware' => 'Semgrep malware rules',
            'jscpd' => 'jscpd duplication',
            'gitleaks' => 'gitleaks secrets',
            default => "the {$tool} heuristic",
        };
    }

    /**
     * @return array{tool: string, kind: string, message: string, seconds: int}
     */
    public function toArray(): array
    {
        return ['tool' => $this->tool, 'kind' => $this->kind, 'message' => $this->message, 'seconds' => $this->seconds];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['tool'] ?? ''), (string) ($data['kind'] ?? 'error'), (string) ($data['message'] ?? ''), (int) ($data['seconds'] ?? 0));
    }

    /**
     * @param  iterable<array<string, mixed>>|null  $rows
     * @return list<self>
     */
    public static function list(?iterable $rows): array
    {
        $failures = [];
        foreach ($rows ?? [] as $row) {
            $failures[] = self::fromArray((array) $row);
        }

        return $failures;
    }
}
