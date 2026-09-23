<?php

namespace App\Models;

use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use Database\Factories\FindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scan_id', 'tool', 'rule_id', 'category', 'severity', 'file_path', 'line', 'symbol', 'message', 'snippet'])]
class Finding extends Model
{
    /** @use HasFactory<FindingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => FindingCategory::class,
            'severity' => Severity::class,
            'line' => 'integer',
        ];
    }

    /**
     * `path:line in Symbol`, matching what the LLM was shown.
     */
    public function location(): string
    {
        return $this->file_path.($this->line !== null ? ':'.$this->line : '').($this->symbol !== null ? ' in '.$this->symbol : '');
    }

    /** @return BelongsTo<Scan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
