<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;

/**
 * Maps tool-specific rule ids and levels onto the shared finding schema.
 */
final class CategoryMapper
{
    /**
     * @return array{0: FindingCategory, 1: Severity}
     */
    public static function phpstan(?string $identifier): array
    {
        $id = (string) $identifier;

        return match (true) {
            str_starts_with($id, 'deadCode.'), str_contains($id, '.always'), str_starts_with($id, 'unreachable') => [FindingCategory::DeadCode, Severity::Low],
            str_starts_with($id, 'syntax'), str_starts_with($id, 'parse') => [FindingCategory::Other, Severity::High],
            str_starts_with($id, 'catch.'), str_starts_with($id, 'throws.'), str_starts_with($id, 'exception.') => [FindingCategory::ErrorHandling, Severity::Medium],
            // "Unnecessary" checks: over-defensive code, not a type hole.
            $id === 'nullsafe.neverNull', str_ends_with($id, '.alreadyNarrowedType'), str_starts_with($id, 'isset.'), str_starts_with($id, 'nullCoalesce.') => [FindingCategory::Style, Severity::Low],
            default => [FindingCategory::TypeSafety, Severity::Medium],
        };
    }

    /**
     * @return array{0: FindingCategory, 1: Severity}
     */
    public static function eslint(?string $ruleId, int $level, bool $fatal): array
    {
        if ($fatal) {
            return [FindingCategory::Other, Severity::High];
        }

        $rule = (string) $ruleId;
        $severity = $level === 2 ? Severity::Medium : Severity::Low;

        $category = match (true) {
            in_array($rule, ['no-eval', 'no-implied-eval', 'no-new-func', 'no-script-url'], true) => FindingCategory::Security,
            in_array($rule, ['no-unused-vars', '@typescript-eslint/no-unused-vars', 'no-unreachable', 'no-useless-return', 'no-unused-expressions', 'no-empty-function', '@typescript-eslint/no-empty-function', 'no-constant-condition'], true) => FindingCategory::DeadCode,
            in_array($rule, ['no-empty', 'no-ex-assign', 'no-unsafe-finally', 'no-throw-literal', 'prefer-promise-reject-errors'], true) => FindingCategory::ErrorHandling,
            in_array($rule, ['max-lines', 'max-lines-per-function', 'complexity', 'max-depth', 'max-params', 'max-statements'], true) => FindingCategory::Complexity,
            str_starts_with($rule, '@typescript-eslint/') || in_array($rule, ['eqeqeq', 'no-undef', 'no-redeclare', 'use-isnan', 'valid-typeof'], true) => FindingCategory::TypeSafety,
            in_array($rule, ['no-debugger', 'no-alert', 'no-console'], true) => FindingCategory::Slop,
            default => FindingCategory::Style,
        };

        // Style rules are Low whatever level the bundled config runs them at: 239 `no-var` hits in a legacy static/js
        // tree are a formatter's job, not a reason to score a 32k-line application at zero. `eqeqeq` is the same shape.
        if ($category === FindingCategory::Style || in_array($rule, ['eqeqeq', 'no-var', 'prefer-const', 'no-redeclare'], true)) {
            $severity = Severity::Low;
        }

        return [$category, $severity];
    }

    /**
     * Ruff rule codes by family. Style families are Low whatever they are;
     * an undefined name or a syntax error is the Python equivalent of a type
     * error and stays High.
     *
     * @return array{0: FindingCategory, 1: Severity}
     */
    public static function ruff(string $code): array
    {
        return match (true) {
            str_starts_with($code, 'E9') || $code === 'F821' || $code === 'F822' || $code === 'F823' => [FindingCategory::TypeSafety, Severity::High],
            in_array($code, ['F401', 'F841', 'F811', 'F842'], true) || str_starts_with($code, 'ARG') || str_starts_with($code, 'ERA') => [FindingCategory::DeadCode, Severity::Low],
            str_starts_with($code, 'F') => [FindingCategory::TypeSafety, Severity::Medium],
            in_array($code, ['S102', 'S307', 'S602', 'S605', 'S609', 'S324'], true) => [FindingCategory::Security, Severity::High],
            str_starts_with($code, 'S') => [FindingCategory::Security, Severity::Medium],
            str_starts_with($code, 'BLE') || in_array($code, ['B904', 'B012', 'B030', 'E722', 'TRY002', 'TRY200', 'TRY201', 'TRY302', 'TRY400', 'TRY401'], true) => [FindingCategory::ErrorHandling, Severity::Medium],
            str_starts_with($code, 'TRY') => [FindingCategory::ErrorHandling, Severity::Low],
            str_starts_with($code, 'C9') => [FindingCategory::Complexity, Severity::Medium],
            str_starts_with($code, 'B') || str_starts_with($code, 'PLE') => [FindingCategory::TypeSafety, Severity::Medium],
            str_starts_with($code, 'T20') => [FindingCategory::Slop, Severity::Low],
            str_starts_with($code, 'PLW') => [FindingCategory::Other, Severity::Low],
            default => [FindingCategory::Style, Severity::Low],
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{0: FindingCategory, 1: Severity}
     */
    public static function semgrep(string $level, array $metadata): array
    {
        $category = FindingCategory::tryFrom((string) ($metadata['sentinel_category'] ?? '')) ?? match (strtolower((string) ($metadata['category'] ?? ''))) {
            'security' => FindingCategory::Security,
            'maintainability', 'best-practice' => FindingCategory::Style,
            default => FindingCategory::Other,
        };

        $severity = match (strtoupper($level)) {
            'ERROR' => Severity::High,
            'WARNING' => Severity::Medium,
            default => Severity::Low,
        };

        return [$category, $severity];
    }
}
