<?php

declare(strict_types=1);

namespace App\Scanning\Data;

use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;

final class Finding
{
    /**
     * @param  string|null  $symbol  Enclosing class/function, e.g. `App\Services\InvoiceService::render()`.
     *                               Only ever set from an AST or a tool's own output; never guessed.
     */
    public function __construct(
        public readonly string $tool,
        public readonly ?string $ruleId,
        public readonly FindingCategory $category,
        public readonly Severity $severity,
        public readonly string $filePath,
        public readonly ?int $line,
        public readonly string $message,
        public readonly ?string $snippet = null,
        public readonly ?string $symbol = null,
    ) {}

    /**
     * @return array{tool: string, rule_id: string|null, category: string, severity: string, file_path: string, line: int|null, symbol: string|null, message: string, snippet: string|null}
     */
    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'rule_id' => $this->ruleId,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'file_path' => $this->filePath,
            'line' => $this->line,
            'symbol' => $this->symbol,
            'message' => $this->message,
            'snippet' => $this->snippet,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tool: (string) $data['tool'],
            ruleId: isset($data['rule_id']) ? (string) $data['rule_id'] : null,
            category: FindingCategory::from((string) $data['category']),
            severity: Severity::from((string) $data['severity']),
            filePath: (string) $data['file_path'],
            line: isset($data['line']) ? (int) $data['line'] : null,
            message: (string) $data['message'],
            snippet: isset($data['snippet']) ? (string) $data['snippet'] : null,
            symbol: isset($data['symbol']) ? (string) $data['symbol'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        return self::fromArray(array_merge($this->toArray(), $overrides));
    }

    /**
     * Human location: `path:line in Symbol`, exactly what the LLM and the UI show.
     */
    public function location(): string
    {
        return $this->filePath
            .($this->line !== null ? ':'.$this->line : '')
            .($this->symbol !== null ? ' in '.$this->symbol : '');
    }

    public function isSecurityCritical(): bool
    {
        return $this->category->isSecurityCritical();
    }
}
